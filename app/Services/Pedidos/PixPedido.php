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
    public const PRAZO_MINUTOS = 60;

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
            'Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido) . ' - ' . $nomeRestaurante, 'pedido:' . $pedido['id']);
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
                TempoReal::publicar($idRestaurante, 'pedidos', 'pedido.pix.apos.cancelar', ['id' => $idPedido, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido)]);
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
            TempoReal::registrar($this->db, $idRestaurante, 'pedidos', 'pedido.novo', ['id' => $idPedido, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido)]);
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
     * Enquanto o cliente acompanha o pedido: confere o pagamento e tenta expirar quando devido.
     * A rotina CLI faz a mesma verificacao mesmo sem nenhuma tela aberta.
     * @param array<string,mixed> $pedido @return array<string,mixed>
     */
    public function acompanhar(array $pedido): array
    {
        if ($pedido['status'] !== 'aguardando_pagamento' || empty($pedido['pix_cobranca_id'])) {
            return $pedido;
        }
        $idRestaurante = (int) $pedido['id_restaurante'];
        $idPedido = (int) $pedido['id'];
        $expirou = !empty($pedido['pix_expira_em']) && strtotime((string) $pedido['pix_expira_em']) <= time();
        if ($expirou) {
            // Evita que varias abas do cliente consultem/cancelem a mesma cobranca em paralelo.
            if (RateLimiter::padrao(1, 30)->permitir('pix-expirar|' . $idPedido)) {
                try {
                    $this->expirar($idRestaurante, $idPedido);
                } catch (Throwable $erro) {
                    error_log('[Cardapim] Pix expirado pendente de conciliacao: pedido ' . $idPedido . ' (' . get_class($erro) . ').');
                }
            }
        } elseif (RateLimiter::padrao(1, 8)->permitir('pix-conferir|' . $idPedido)) {
            try {
                [$chave, $ambiente] = $this->acesso($idRestaurante);
                $cobranca = $this->api->cobranca($chave, $ambiente, (string) $pedido['pix_cobranca_id']);
                if (in_array($cobranca['status'], ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                    $this->confirmar($idRestaurante, (string) $pedido['pix_cobranca_id'], $cobranca['valor'], 'consulta');
                }
            } catch (Throwable $erro) {
                // O webhook ou a proxima consulta podera confirmar. Nao cancela sem consultar o Asaas.
            }
        }
        return (new PedidoRepository($this->db))->buscar($idRestaurante, $idPedido) ?? $pedido;
    }

    /**
     * Concilia e expira um Pix vencido. A exclusao no Asaas precisa ter sucesso ANTES
     * do cancelamento local, para nao deixar um QR Code ativo para pedido cancelado.
     * @return string cancelado | confirmado | ignorado
     * @throws IntegracaoErro Quando o Asaas nao responde ou o estado e inconclusivo. Tentar novamente depois.
     */
    public function expirar(int $idRestaurante, int $idPedido): string
    {
        $pedido = (new PedidoRepository($this->db))->buscar($idRestaurante, $idPedido);
        if ($pedido === null
            || $pedido['status'] !== 'aguardando_pagamento'
            || $pedido['pagamento_status'] !== 'pendente'
            || $pedido['forma_pagamento'] !== 'pix_online'
            || empty($pedido['pix_cobranca_id'])
            || empty($pedido['pix_expira_em'])
            || strtotime((string) $pedido['pix_expira_em']) > time()) {
            return 'ignorado';
        }

        [$chave, $ambiente] = $this->acesso($idRestaurante);
        $idCobranca = (string) $pedido['pix_cobranca_id'];
        $cobranca = $this->api->cobranca($chave, $ambiente, $idCobranca);
        if (in_array($cobranca['status'], ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
            $resultado = $this->confirmar($idRestaurante, $idCobranca, $cobranca['valor'], 'consulta');
            if ($resultado === 'confirmado') {
                return 'confirmado';
            }
            if ($resultado === 'repetido' || $resultado === 'pago_apos_cancelar') {
                return 'ignorado';
            }
            throw new IntegracaoErro('Pix com pagamento confirmado, mas conciliacao do pedido pendente.');
        }
        if (!in_array($cobranca['status'], ['PENDING', 'OVERDUE'], true)) {
            throw new IntegracaoErro('Situacao do Pix inconclusiva. Cancelamento adiado.');
        }

        // O DELETE do Asaas recusa cobrancas ja pagas. Nunca cancelar o pedido se falhar.
        $this->api->cancelarCobranca($chave, $ambiente, $idCobranca);
        // UPDATE condicionado em uma transacao: webhook concorrente pode ter confirmado o pedido.
        return (new CancelarPedido($this->db))->cancelar(
            $idRestaurante, $idPedido, 'Pix não foi pago em ' . self::PRAZO_MINUTOS . ' minutos.',
            null, null, true
        ) ? 'cancelado' : 'ignorado';
    }

    /** Prazo do QR gerado pelo motoboy na entrega (o pedido nunca e cancelado por isso: ja esta em rota). */
    public const PRAZO_ENTREGA_MINUTOS = 30;

    /**
     * Pix por QR Code na entrega (motoboy, "Trocar forma de pagamento"): cobranca na conta Asaas do restaurante com o
     * total do pedido (ja com a taxa do Pix pelo app), referencia "entrega:{id}". Mesmo valor e QR ainda valendo:
     * devolve o mesmo QR. O pedido ja deve estar com a forma pix_online (TrocarFormaPagamento).
     * @param array<string,mixed> $pedido
     * @throws IntegracaoErro
     * @return array{copia_cola:string,expira_em:string,total:int}
     */
    public function gerarNaEntrega(array $pedido, string $cpfDigitado = ''): array
    {
        $idRest = (int) $pedido['id_restaurante'];
        $idPedido = (int) $pedido['id'];
        $total = Numero::centavos((string) $pedido['total']);
        if (!empty($pedido['pix_cobranca_id']) && !empty($pedido['pix_copia_cola']) && !empty($pedido['pix_expira_em'])
            && strtotime((string) $pedido['pix_expira_em']) > time() + 60) {
            return ['copia_cola' => (string) $pedido['pix_copia_cola'], 'expira_em' => (string) $pedido['pix_expira_em'], 'total' => $total];
        }
        [$chave, $ambiente] = $this->acesso($idRest);
        $customer = $this->clienteAsaas($pedido, $cpfDigitado, $chave, $ambiente);
        if (!empty($pedido['pix_cobranca_id'])) {
            $this->removerCobranca($idRest, (string) $pedido['pix_cobranca_id']); // valor mudou ou QR vencido
        }
        $q = $this->db->prepare('SELECT nome FROM restaurantes WHERE id = ?');
        $q->execute([$idRest]);
        $nome = (string) ($q->fetchColumn() ?: '');
        $idCobranca = $this->api->criarCobrancaPix($chave, $ambiente, $customer, (string) $pedido['total'], date('Y-m-d'),
            'Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido) . ' - ' . $nome, 'entrega:' . $idPedido);
        try {
            $qr = $this->api->qrCodePix($chave, $ambiente, $idCobranca);
        } catch (IntegracaoErro $erro) {
            $this->removerCobranca($idRest, $idCobranca);
            throw $erro;
        }
        $expira = date('Y-m-d H:i:s', time() + self::PRAZO_ENTREGA_MINUTOS * 60);
        $u = $this->db->prepare("UPDATE pedidos SET pix_cobranca_id = ?, pix_copia_cola = ?, pix_expira_em = ?, atualizado_em = ?
            WHERE id = ? AND id_restaurante = ? AND pagamento_status = 'pendente' AND forma_pagamento = 'pix_online' AND status = 'saiu_entrega'");
        $u->execute([$idCobranca, $qr['payload'], $expira, date('Y-m-d H:i:s'), $idPedido, $idRest]);
        if ($u->rowCount() !== 1) {
            $this->removerCobranca($idRest, $idCobranca);
            throw new IntegracaoErro('A entrega mudou enquanto o Pix era gerado.');
        }
        return ['copia_cola' => (string) $qr['payload'], 'expira_em' => $expira, 'total' => $total];
    }

    /**
     * Pix da entrega pago (aviso do Asaas com "entrega:{id}" ou consulta): o pagamento fica "pago" e o motoboy so
     * confirma a entrega. Repetido nao faz nada. @return string confirmado | repetido | valor_diferente | pago_apos_cancelar | nao_encontrado
     */
    public function confirmarEntrega(int $idRestaurante, string $idCobranca, string $valor, string $origem): string
    {
        $repo = new PedidoRepository($this->db);
        $pedido = $repo->porCobranca($idRestaurante, $idCobranca);
        if ($pedido === null) {
            return 'nao_encontrado';
        }
        if (Numero::centavos($valor) !== Numero::centavos((string) $pedido['total'])) {
            error_log('[Cardapim] Pix da entrega com valor diferente do pedido ' . $pedido['id']);
            return 'valor_diferente';
        }
        $idPedido = (int) $pedido['id'];
        $agora = date('Y-m-d H:i:s');
        $q = $this->db->prepare("UPDATE pedidos SET pagamento_status = 'pago', pago_em = ?, atualizado_em = ?
            WHERE id = ? AND id_restaurante = ? AND pagamento_status <> 'pago'");
        $q->execute([$agora, $agora, $idPedido, $idRestaurante]);
        if ($q->rowCount() !== 1) {
            return 'repetido';
        }
        if ($pedido['status'] === 'cancelado') {
            $repo->registrarEvento($idRestaurante, $idPedido, 'cancelado', 'Pix da entrega pago depois do cancelamento. Devolva o valor ao cliente pelo Asaas.');
            TempoReal::publicar($idRestaurante, 'pedidos', 'pedido.pix.apos.cancelar', ['id' => $idPedido, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido)]);
            return 'pago_apos_cancelar';
        }
        $repo->registrarEvento($idRestaurante, $idPedido, (string) $pedido['status'], 'Pix da entrega confirmado' . ($origem === 'webhook' ? ' pelo Asaas.' : '.'));
        TempoReal::publicar($idRestaurante, 'entregas', 'entrega.atualizada', ['id' => $idPedido, 'pix' => 'pago']);
        TempoReal::publicar($idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => $idPedido, 'numero' => \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido), 'status' => (string) $pedido['status']]);
        return 'confirmado';
    }

    /**
     * Enquanto o QR da entrega esta na tela do motoboy: confere a cobranca (reserva do aviso). @param array<string,mixed> $pedido
     * @return string pago | pendente | sem_qr
     */
    public function situacaoEntrega(array $pedido): string
    {
        if ($pedido['pagamento_status'] === 'pago') {
            return 'pago';
        }
        if (empty($pedido['pix_cobranca_id']) || $pedido['forma_pagamento'] !== 'pix_online') {
            return 'sem_qr';
        }
        $idRest = (int) $pedido['id_restaurante'];
        if (RateLimiter::padrao(1, 5)->permitir('pix-entrega|' . (int) $pedido['id'])) {
            try {
                [$chave, $ambiente] = $this->acesso($idRest);
                $cobranca = $this->api->cobranca($chave, $ambiente, (string) $pedido['pix_cobranca_id']);
                if (in_array($cobranca['status'], ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)
                    && $this->confirmarEntrega($idRest, (string) $pedido['pix_cobranca_id'], $cobranca['valor'], 'consulta') === 'confirmado') {
                    return 'pago';
                }
            } catch (Throwable) {
                // O aviso do Asaas ou a proxima consulta confirma.
            }
        }
        return 'pendente';
    }

    /** Cliente da cobranca no Asaas: o do cardapio (guardado) ou, no pedido feito no caixa, um cadastro so com nome e CPF. @param array<string,mixed> $pedido */
    private function clienteAsaas(array $pedido, string $cpfDigitado, string $chave, string $ambiente): string
    {
        $idRest = (int) $pedido['id_restaurante'];
        $idCliente = (int) ($pedido['id_cliente'] ?? 0);
        $cpfSalvo = null;
        if ($idCliente > 0) {
            $q = $this->db->prepare('SELECT id_externo FROM asaas_clientes WHERE id_restaurante = ? AND id_cliente = ? AND ambiente = ?');
            $q->execute([$idRest, $idCliente, $ambiente]);
            $existente = $q->fetchColumn();
            if (is_string($existente) && $existente !== '') {
                return $existente;
            }
            $c = $this->db->prepare('SELECT cpf FROM clientes WHERE id = ?');
            $c->execute([$idCliente]);
            $cpfSalvo = $c->fetchColumn() ?: null;
        }
        $cpf = is_string($cpfSalvo) && $cpfSalvo !== '' ? $cpfSalvo : \Cardapim\Shared\Validation\Documento::normalizarCpf($cpfDigitado);
        if ($cpf === null || !\Cardapim\Shared\Validation\Documento::cpfValido($cpf)) {
            throw new IntegracaoErro('CPF_NECESSARIO');
        }
        $customer = $this->api->criarCliente($chave, $ambiente, mb_substr((string) ($pedido['cliente_nome'] ?: 'Cliente'), 0, 80), $cpf,
            (string) ($pedido['cliente_whatsapp'] ?? ''), $idCliente > 0 ? 'cardapim-cliente:' . $idCliente : 'cardapim-entrega:' . (int) $pedido['id']);
        if ($idCliente > 0) {
            $this->db->prepare('INSERT INTO asaas_clientes (id_restaurante, id_cliente, ambiente, id_externo) VALUES (?, ?, ?, ?)')
                ->execute([$idRest, $idCliente, $ambiente, $customer]);
        }
        return $customer;
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
