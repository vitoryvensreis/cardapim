<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

/**
 * Pix pelo app: cobranca na conta Asaas DO RESTAURANTE (o dinheiro cai direto para ele).
 * - O pedido nasce "aguardando pagamento"; a cozinha so recebe depois do Pix confirmado.
 * - Confirmacao pelo aviso do Asaas (webhook) e, de reserva, consultando a cobranca enquanto o cliente espera.
 * - Sem pagamento em PRAZO_MINUTOS: pedido cancelado, estoque devolvido e cobranca removida no Asaas.
 */
final class PixPedido
{
    public const PRAZO_MINUTOS = 30;

    public function __construct(private readonly PDO $db, private readonly AsaasApi $api = new AsaasApi())
    {
    }

    public function disponivel(int $idRestaurante): bool
    {
        $i = (new IntegracaoRepository($this->db))->buscar($idRestaurante, 'asaas');
        return $i['status'] === 'conectado' && $i['tem_credenciais'];
    }

    /**
     * Gera a cobranca e o codigo copia e cola. @param array<string,mixed> $pedido @param array<string,mixed> $cliente
     * @throws IntegracaoErro
     */
    public function gerar(array $pedido, array $cliente, string $cpf, string $nomeRestaurante): void
    {
        [$chave, $ambiente] = $this->acesso((int) $pedido['id_restaurante']);
        $idCliente = (int) $cliente['id'];
        $idRest = (int) $pedido['id_restaurante'];
        $q = $this->db->prepare('SELECT id_externo FROM asaas_clientes WHERE id_restaurante = ? AND id_cliente = ? AND ambiente = ?');
        $q->execute([$idRest, $idCliente, $ambiente]);
        $customer = $q->fetchColumn();
        if (!is_string($customer) || $customer === '') {
            $customer = $this->api->criarCliente($chave, $ambiente, (string) $cliente['nome'], $cpf, (string) $cliente['whatsapp'], 'cardapim-cliente:' . $idCliente);
            $this->db->prepare('INSERT INTO asaas_clientes (id_restaurante, id_cliente, ambiente, id_externo) VALUES (?, ?, ?, ?)')
                ->execute([$idRest, $idCliente, $ambiente, $customer]);
        }
        $idCobranca = $this->api->criarCobrancaPix($chave, $ambiente, $customer, (string) $pedido['total'], date('Y-m-d'),
            'Pedido #' . $pedido['numero'] . ' - ' . $nomeRestaurante, 'pedido:' . $pedido['id']);
        try {
            $qr = $this->api->qrCodePix($chave, $ambiente, $idCobranca);
        } catch (IntegracaoErro $erro) {
            $this->removerCobranca($idRest, $idCobranca);
            throw $erro;
        }
        $this->db->prepare('UPDATE pedidos SET pix_cobranca_id = ?, pix_copia_cola = ?, pix_expira_em = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([$idCobranca, $qr['payload'], date('Y-m-d H:i:s', time() + self::PRAZO_MINUTOS * 60), date('Y-m-d H:i:s'), (int) $pedido['id'], $idRest]);
    }

    /**
     * Pix pago: o pedido vai para a cozinha. Repetido (o Asaas avisa "pelo menos uma vez") nao faz nada.
     * @return string confirmado | repetido | valor_diferente | pago_apos_cancelar | nao_encontrado
     */
    public function confirmar(int $idRestaurante, string $idCobranca, string $valor, string $origem): string
    {
        $repo = new PedidoRepository($this->db);
        $pedido = $repo->porCobranca($idRestaurante, $idCobranca);
        if ($pedido === null) {
            return 'nao_encontrado';
        }
        if (Numero::centavos($valor) !== Numero::centavos((string) $pedido['total'])) {
            error_log('[Cardapim] Pix com valor diferente do pedido ' . $pedido['id']);
            return 'valor_diferente';
        }
        $idPedido = (int) $pedido['id'];
        if ($pedido['status'] === 'cancelado') {
            $q = $this->db->prepare("UPDATE pedidos SET pagamento_status = 'pago', pago_em = ? WHERE id = ? AND id_restaurante = ? AND pagamento_status <> 'pago'");
            $q->execute([date('Y-m-d H:i:s'), $idPedido, $idRestaurante]);
            if ($q->rowCount() === 1) {
                $repo->registrarEvento($idRestaurante, $idPedido, 'cancelado', 'Pix pago depois do cancelamento. Devolva o valor ao cliente pelo Asaas.');
                TempoReal::publicar($idRestaurante, 'pedidos', 'pedido.pix.apos.cancelar', ['id' => $idPedido, 'numero' => (int) $pedido['numero']]);
                return 'pago_apos_cancelar';
            }
            return 'repetido';
        }
        $this->db->beginTransaction();
        try {
            $agora = date('Y-m-d H:i:s');
            $q = $this->db->prepare("UPDATE pedidos SET status = 'recebido', pagamento_status = 'pago', pago_em = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND status = 'aguardando_pagamento'");
            $q->execute([$agora, $agora, $idPedido, $idRestaurante]);
            if ($q->rowCount() !== 1) {
                $this->db->rollBack();
                return 'repetido';
            }
            $repo->registrarEvento($idRestaurante, $idPedido, 'recebido', 'Pix confirmado' . ($origem === 'webhook' ? ' pelo Asaas.' : '.'));
            AvisosWhatsApp::enfileirar($this->db, $idRestaurante, $idPedido, 'recebido');
            TempoReal::registrar($this->db, $idRestaurante, 'pedidos', 'pedido.novo', ['id' => $idPedido, 'numero' => (int) $pedido['numero']]);
            $this->db->commit();
            return 'confirmado';
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }

    /**
     * Enquanto o cliente espera na tela do Pix: confere a cobranca (no maximo a cada 8 s) e cancela se passou do prazo.
     * @param array<string,mixed> $pedido @return array<string,mixed> pedido atualizado
     */
    public function acompanhar(array $pedido): array
    {
        if ($pedido['status'] !== 'aguardando_pagamento' || empty($pedido['pix_cobranca_id'])) {
            return $pedido;
        }
        $idRest = (int) $pedido['id_restaurante'];
        $idPedido = (int) $pedido['id'];
        if (RateLimiter::padrao(1, 8)->permitir('pix-conferir|' . $idPedido)) {
            try {
                [$chave, $ambiente] = $this->acesso($idRest);
                $c = $this->api->cobranca($chave, $ambiente, (string) $pedido['pix_cobranca_id']);
                if (in_array($c['status'], ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                    $this->confirmar($idRest, (string) $pedido['pix_cobranca_id'], $c['valor'], 'consulta');
                }
            } catch (IntegracaoErro|Throwable) {
                // Sem resposta do Asaas agora: o aviso (webhook) ou a proxima consulta confirma.
            }
        }
        $atual = (new PedidoRepository($this->db))->buscar($idRest, $idPedido) ?? $pedido;
        if ($atual['status'] === 'aguardando_pagamento' && !empty($atual['pix_expira_em']) && strtotime((string) $atual['pix_expira_em']) < time()) {
            if ((new CancelarPedido($this->db))->cancelar($idRest, $idPedido, 'Pix não foi pago em ' . self::PRAZO_MINUTOS . ' minutos.')) {
                $this->removerCobranca($idRest, (string) $atual['pix_cobranca_id']);
            }
            $atual = (new PedidoRepository($this->db))->buscar($idRest, $idPedido) ?? $atual;
        }
        return $atual;
    }

    public function removerCobranca(int $idRestaurante, string $idCobranca): void
    {
        try {
            [$chave, $ambiente] = $this->acesso($idRestaurante);
            $this->api->cancelarCobranca($chave, $ambiente, $idCobranca);
        } catch (Throwable) {
            error_log('[Cardapim] Nao foi possivel remover a cobranca Pix no Asaas.');
        }
    }

    /** @return array{0:string,1:string} chave e ambiente */
    private function acesso(int $idRestaurante): array
    {
        $repo = new IntegracaoRepository($this->db);
        $i = $repo->buscar($idRestaurante, 'asaas');
        $cred = $repo->credenciais($idRestaurante, 'asaas');
        if ($i['status'] !== 'conectado' || empty($cred['chave']) || !in_array($i['ambiente'], ['producao', 'sandbox'], true)) {
            throw new IntegracaoErro('O Pix pelo app não está disponível neste restaurante agora.');
        }
        return [(string) $cred['chave'], (string) $i['ambiente']];
    }
}
