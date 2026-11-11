<?php

declare(strict_types=1);

namespace Cardapim\Services\Garcom;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

/**
 * Pix com QR Code na mesa: cobranca na conta Asaas DO RESTAURANTE para o que falta pagar da comanda.
 *
 * - O garcom mostra o QR Code no celular; o cliente paga no app do banco.
 * - Confirmado pelo aviso do Asaas (webhook, externalReference "comanda:{id}") ou pela consulta enquanto a tela espera.
 * - Confirmado = pagamento "Pix pelo app" no caixa aberto; pagou tudo = conta fechada e mesa livre.
 * - Idempotente: a mesma cobranca nunca entra duas vezes (UNIQUE cobranca_id + UPDATE condicionado ao status).
 * - Pago depois de trocar a forma (ou com a conta ja fechada): o valor entra se ainda faltar; se sobrar, fica
 *   registrado na linha do tempo para devolver pelo Asaas.
 */
final class ComandaPix
{
    public const PRAZO_MINUTOS = 30;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly AsaasApi $api = new AsaasApi())
    {
    }

    public function disponivel(): bool
    {
        $i = (new IntegracaoRepository($this->db))->buscar($this->idRestaurante, 'asaas');
        return $i['status'] === 'conectado' && $i['tem_credenciais'];
    }

    /**
     * Gera (ou reaproveita) o Pix do que falta pagar. @param array<string,mixed> $c ComandaService::detalhes
     * @return Resultado id = comanda_pix.id
     */
    public function gerar(array $c, ?int $idUser, string $nomeRestaurante): Resultado
    {
        if (!$c['aberta']) {
            return Resultado::falha('Esta conta já foi fechada.');
        }
        $restante = (int) $c['restante'];
        if ($restante < 100) {
            return Resultado::falha($restante <= 0 ? 'Não falta nada para pagar.' : 'Pix pelo app a partir de R$ 1,00. Receba o restante de outra forma.');
        }
        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        if ($caixa === null) {
            return Resultado::falha('O caixa está fechado. Peça para abrir o caixa antes de receber.');
        }
        $idPedido = (int) $c['pedido']['id'];
        $valor = CaixaService::dec($restante);

        // Ja existe um Pix esperando com o mesmo valor: mostra o mesmo QR (o cliente pode ja ter escaneado).
        $q = $this->db->prepare("SELECT id, valor, cobranca_id, expira_em FROM comanda_pix WHERE id_restaurante = ? AND id_pedido = ? AND status = 'pendente' ORDER BY id DESC");
        $q->execute([$this->idRestaurante, $idPedido]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (Numero::centavos((string) $p['valor']) === $restante && strtotime((string) $p['expira_em']) > time() + 60) {
                return Resultado::sucesso('', (int) $p['id']);
            }
            $this->cancelar((int) $p['id']); // valor mudou (item novo, 10% tirado...): o QR antigo nao vale mais
        }

        try {
            [$chave, $ambiente] = $this->acesso();
            $cliente = $this->clienteSalao($chave, $ambiente, $nomeRestaurante);
            $idCobranca = $this->api->criarCobrancaPix($chave, $ambiente, $cliente, $valor, date('Y-m-d'),
                $c['rotulo'] . ' - pedido #' . (int) $c['pedido']['numero'] . ' - ' . $nomeRestaurante, 'comanda:' . $idPedido);
            try {
                $qr = $this->api->qrCodePix($chave, $ambiente, $idCobranca);
            } catch (IntegracaoErro $erro) {
                $this->removerCobranca($idCobranca);
                throw $erro;
            }
        } catch (IntegracaoErro $erro) {
            return Resultado::falha('Não deu para gerar o Pix agora: ' . rtrim($erro->getMessage(), '.') . '. Receba de outra forma.');
        } catch (Throwable $erro) {
            error_log('[Cardapim] Pix da mesa nao gerado: ' . get_class($erro));
            return Resultado::falha('Não deu para gerar o Pix agora. Receba de outra forma.');
        }
        $agora = date('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO comanda_pix (id_restaurante, id_pedido, id_caixa, id_user, cobranca_id, ambiente, valor, copia_cola, status, criado_em, expira_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pendente\', ?, ?)')
            ->execute([$this->idRestaurante, $idPedido, (int) $caixa['id'], $idUser, $idCobranca, $ambiente, $valor, $qr['payload'], $agora,
                date('Y-m-d H:i:s', time() + self::PRAZO_MINUTOS * 60)]);
        $q = $this->db->prepare('SELECT id FROM comanda_pix WHERE cobranca_id = ? AND id_restaurante = ?');
        $q->execute([$idCobranca, $this->idRestaurante]);
        (new PedidoRepository($this->db))->registrarEvento($this->idRestaurante, $idPedido, 'aberto', 'Pix de ' . Numero::reais($valor) . ' gerado na mesa.', $idUser);
        return Resultado::sucesso('', (int) $q->fetchColumn());
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM comanda_pix WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $this->idRestaurante]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }

    /**
     * Enquanto o QR esta na tela: consulta a cobranca (no maximo a cada 5 s) e expira o que passou do prazo.
     * @return array<string,mixed>|null linha atualizada
     */
    public function acompanhar(int $id): ?array
    {
        $p = $this->buscar($id);
        if ($p === null || $p['status'] !== 'pendente') {
            return $p;
        }
        if (RateLimiter::padrao(1, 5)->permitir('pix-mesa|' . $id)) {
            try {
                [$chave, $ambiente] = $this->acesso();
                $c = $this->api->cobranca($chave, $ambiente, (string) $p['cobranca_id']);
                if (in_array($c['status'], ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
                    $this->confirmar((string) $p['cobranca_id'], $c['valor'], 'consulta');
                }
            } catch (Throwable) {
                // Sem resposta do Asaas agora: o aviso (webhook) ou a proxima consulta confirma.
            }
        }
        $p = $this->buscar($id);
        if ($p !== null && $p['status'] === 'pendente' && strtotime((string) $p['expira_em']) < time()) {
            $this->cancelar($id);
            $p = $this->buscar($id);
        }
        return $p;
    }

    /** O garcom trocou a forma de pagamento (ou o QR venceu): remove a cobranca no Asaas. */
    public function cancelar(int $id): void
    {
        $p = $this->buscar($id);
        if ($p === null || $p['status'] !== 'pendente') {
            return;
        }
        $u = $this->db->prepare("UPDATE comanda_pix SET status = 'cancelado' WHERE id = ? AND id_restaurante = ? AND status = 'pendente'");
        $u->execute([$id, $this->idRestaurante]);
        if ($u->rowCount() === 1) {
            $this->removerCobranca((string) $p['cobranca_id']);
        }
    }

    /**
     * Pix pago. Repetido nao faz nada.
     * @return string confirmado | fechou | repetido | valor_diferente | sobrou | nao_encontrado
     */
    public function confirmar(string $idCobranca, string $valor, string $origem): string
    {
        $q = $this->db->prepare('SELECT * FROM comanda_pix WHERE cobranca_id = ? AND id_restaurante = ?');
        $q->execute([$idCobranca, $this->idRestaurante]);
        $pix = $q->fetch(PDO::FETCH_ASSOC);
        if ($pix === false) {
            return 'nao_encontrado';
        }
        if ($pix['status'] === 'pago') {
            return 'repetido';
        }
        $centavos = Numero::centavos((string) $pix['valor']);
        if (Numero::centavos($valor) !== $centavos) {
            error_log('[Cardapim] Pix da mesa com valor diferente da cobranca ' . $pix['id']);
            return 'valor_diferente';
        }
        $idPedido = (int) $pix['id_pedido'];
        $repo = new PedidoRepository($this->db);
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE comanda_pix SET status = 'pago', pago_em = ? WHERE id = ? AND id_restaurante = ? AND status <> 'pago'");
            $u->execute([$agora, (int) $pix['id'], $this->idRestaurante]);
            if ($u->rowCount() !== 1) {
                $this->db->rollBack();
                return 'repetido';
            }
            $pedido = $repo->buscar($this->idRestaurante, $idPedido);
            $restante = $pedido !== null && $pedido['status'] === 'aberto'
                ? max(0, Numero::centavos((string) $pedido['total']) - ComandaService::totalPago($this->db, $this->idRestaurante, $idPedido))
                : 0;
            $entra = min($centavos, $restante);
            $resultado = 'confirmado';
            if ($entra > 0) {
                $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
                $idCaixa = $caixa !== null ? (int) $caixa['id'] : (int) $pix['id_caixa'];
                $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, \'pix_online\', ?, \'0.00\', ?, ?)')
                    ->execute([$idPedido, $this->idRestaurante, $idCaixa, CaixaService::dec($entra), $pix['id_user'] !== null ? (int) $pix['id_user'] : null, $agora]);
                $repo->registrarEvento($this->idRestaurante, $idPedido, 'aberto', 'Pix de ' . Numero::reais(CaixaService::dec($entra)) . ' confirmado' . ($origem === 'webhook' ? ' pelo Asaas.' : '.'));
                if (ComandaService::totalPago($this->db, $this->idRestaurante, $idPedido) >= Numero::centavos((string) $pedido['total'])
                    && ComandaService::fecharPaga($this->db, $this->idRestaurante, $idPedido, $idCaixa, $agora, null, 'Conta paga com Pix pelo app e fechada.')) {
                    $resultado = 'fechou';
                }
                TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => $idCaixa]);
            }
            if ($entra < $centavos) {
                $repo->registrarEvento($this->idRestaurante, $idPedido, $pedido['status'] ?? 'concluido',
                    'Pix de ' . Numero::reais(CaixaService::dec($centavos - $entra)) . ' pago a mais (a conta já estava paga). Devolva ao cliente pelo Asaas.');
                $resultado = $entra > 0 ? $resultado : 'sobrou';
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'mesas', $resultado === 'fechou' ? 'comanda.fechada' : 'comanda.atualizada', ['id' => $idPedido, 'pix' => (int) $pix['id']]);
            $this->db->commit();
            return $resultado;
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }

    /** Cadastro "Clientes do salão" (CNPJ do restaurante) na conta Asaas, criado uma vez e reaproveitado. */
    private function clienteSalao(string $chave, string $ambiente, string $nomeRestaurante): string
    {
        $q = $this->db->prepare('SELECT id_externo FROM asaas_clientes_salao WHERE id_restaurante = ? AND ambiente = ?');
        $q->execute([$this->idRestaurante, $ambiente]);
        $id = $q->fetchColumn();
        if (is_string($id) && $id !== '') {
            return $id;
        }
        $r = $this->db->prepare('SELECT cnpj, telefone FROM restaurantes WHERE id = ?');
        $r->execute([$this->idRestaurante]);
        $rest = $r->fetch(PDO::FETCH_ASSOC) ?: [];
        $cnpj = preg_replace('/\D/', '', (string) ($rest['cnpj'] ?? '')) ?? '';
        if (strlen($cnpj) !== 14) {
            throw new IntegracaoErro('o restaurante precisa ter CNPJ no cadastro para o Pix na mesa');
        }
        $tel = preg_replace('/\D/', '', (string) ($rest['telefone'] ?? '')) ?? '';
        $id = $this->api->criarCliente($chave, $ambiente, mb_substr('Clientes do salão - ' . $nomeRestaurante, 0, 100), $cnpj,
            strlen($tel) === 11 ? $tel : '', 'cardapim-salao:' . $this->idRestaurante);
        $this->db->prepare('INSERT INTO asaas_clientes_salao (id_restaurante, ambiente, id_externo) VALUES (?, ?, ?)')->execute([$this->idRestaurante, $ambiente, $id]);
        return $id;
    }

    private function removerCobranca(string $idCobranca): void
    {
        try {
            [$chave, $ambiente] = $this->acesso();
            $this->api->cancelarCobranca($chave, $ambiente, $idCobranca);
        } catch (Throwable) {
            error_log('[Cardapim] Nao foi possivel remover a cobranca Pix da mesa no Asaas.');
        }
    }

    /** @return array{0:string,1:string} chave e ambiente */
    private function acesso(): array
    {
        $repo = new IntegracaoRepository($this->db);
        $i = $repo->buscar($this->idRestaurante, 'asaas');
        $cred = $repo->credenciais($this->idRestaurante, 'asaas');
        if ($i['status'] !== 'conectado' || empty($cred['chave']) || !in_array($i['ambiente'], ['producao', 'sandbox'], true)) {
            throw new IntegracaoErro('o Pix pelo app não está ligado neste restaurante');
        }
        return [(string) $cred['chave'], (string) $i['ambiente']];
    }
}
