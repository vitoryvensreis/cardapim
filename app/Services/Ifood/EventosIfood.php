<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\PedidoRepository;
use Cardapim\Services\Pedidos\CancelarPedido;
use PDO;
use Throwable;

/**
 * Avisos do iFood (polling): pedido novo, aceito, pronto, saiu, concluido, cancelado e o caminho do entregador.
 * 1) busca os avisos, 2) GUARDA cada um (integracao_eventos, sem repetir), 3) confirma o recebimento ao iFood,
 * 4) trata os guardados; o que falhar e tratado de novo na proxima rodada (ate 10 vezes).
 * Mudancas vindas do iFood nunca geram acao de volta para o iFood (sem idas e voltas).
 */
final class EventosIfood
{
    /** Codigos curtos do iFood => nome completo. */
    private const CODIGOS = [
        'PLC' => 'PLACED', 'CFM' => 'CONFIRMED', 'SPS' => 'SEPARATION_STARTED', 'SPE' => 'SEPARATION_ENDED',
        'RTP' => 'READY_TO_PICKUP', 'DSP' => 'DISPATCHED', 'CON' => 'CONCLUDED', 'CAN' => 'CANCELLED',
        'CAR' => 'CANCELLATION_REQUESTED', 'CAF' => 'CANCELLATION_REQUEST_FAILED', 'CCR' => 'CONSUMER_CANCELLATION_REQUESTED',
        'ADR' => 'ASSIGN_DRIVER', 'GTO' => 'GOING_TO_ORIGIN', 'AAO' => 'ARRIVED_AT_ORIGIN', 'CLT' => 'COLLECTED',
        'AAD' => 'ARRIVED_AT_DESTINATION', 'DDA' => 'DELIVERY_DROP_CODE_REQUESTED', 'KPA' => 'KEEPALIVE',
        'COL' => 'PLACED', 'INT' => 'INTEGRATED',
    ];

    /** Etapas do entregador do iFood => texto para o caixa. */
    public const ETAPAS = [
        'ASSIGN_DRIVER' => 'Entregador a caminho da loja',
        'GOING_TO_ORIGIN' => 'Entregador a caminho da loja',
        'ARRIVED_AT_ORIGIN' => 'Entregador chegou na loja',
        'COLLECTED' => 'Entregador pegou o pedido',
        'DISPATCHED' => 'Saiu para entrega',
        'ARRIVED_AT_DESTINATION' => 'Entregador chegou no cliente',
        'DELIVERY_DRIVER_DEALLOCATED' => 'O iFood está trocando o entregador',
    ];

    private const MAX_TENTATIVAS = 10;

    private readonly PedidoRepository $pedidos;

    public function __construct(private readonly PDO $db, private readonly IfoodConta $conta)
    {
        $this->pedidos = new PedidoRepository($db);
    }

    /** Nome completo do aviso, aceitando o codigo curto ou o completo. */
    public static function codigo(array $evento): string
    {
        $completo = strtoupper(trim((string) ($evento['fullCode'] ?? '')));
        $curto = strtoupper(trim((string) ($evento['code'] ?? '')));
        if ($completo === '' && strlen($curto) > 3) {
            $completo = $curto;
        }
        $completo = (string) preg_replace('/\AORDER_/', '', $completo);
        if ($completo !== '') {
            return $completo;
        }
        return self::CODIGOS[$curto] ?? $curto;
    }

    /** Passos 1 a 3: busca, guarda e confirma ao iFood. @return int avisos recebidos */
    public function receber(string $token): int
    {
        $loja = $this->conta->loja();
        if ($loja === null) {
            return 0;
        }
        $eventos = $this->conta->api()->eventos($token, $loja);
        if ($eventos === []) {
            return 0;
        }
        $repo = new IntegracaoRepository($this->db);
        $ids = [];
        foreach ($eventos as $ev) {
            $merchant = (string) ($ev['merchantId'] ?? $loja);
            if ($merchant !== $loja) {
                $ids[] = (string) $ev['id']; // de outra loja: so confirma (nao deveria vir)
                continue;
            }
            $repo->registrarEvento($this->conta->idRestaurante, 'ifood', self::codigo($ev), mb_substr((string) $ev['id'], 0, 120),
                json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $ids[] = (string) $ev['id'];
        }
        $this->conta->api()->confirmarEventos($token, $ids);
        return count($eventos);
    }

    /** Passo 4: trata os avisos guardados ainda nao tratados (em ordem de chegada). @return int tratados */
    public function tratarPendentes(string $token, int $limite = 100): int
    {
        $q = $this->db->prepare("SELECT id, evento, id_externo, payload, tentativas FROM integracao_eventos
            WHERE escopo = ? AND provedor = 'ifood' AND processado_em IS NULL ORDER BY id LIMIT " . max(1, min(500, $limite)));
        $q->execute([$this->conta->idRestaurante]);
        $tratados = 0;
        $falhou = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $ev = json_decode((string) $linha['payload'], true);
            $ev = is_array($ev) ? $ev : [];
            $pedido = (string) ($ev['orderId'] ?? '');
            if ($pedido !== '' && isset($falhou[$pedido])) {
                continue; // aviso anterior do mesmo pedido falhou: mantem a ordem
            }
            try {
                $this->tratar(self::codigo($ev), $ev, $token);
                $this->marcar((int) $linha['id'], null, true);
                $tratados++;
            } catch (Throwable $erro) {
                $falhou[$pedido] = true;
                $mensagem = $erro instanceof IntegracaoErro ? $erro->getMessage() : get_class($erro) . ': ' . $erro->getMessage();
                $desistir = (int) $linha['tentativas'] + 1 >= self::MAX_TENTATIVAS;
                $this->marcar((int) $linha['id'], $mensagem, $desistir);
                if (!$erro instanceof IntegracaoErro) {
                    error_log('[Cardapim] Aviso do iFood nao tratado (' . $linha['evento'] . '): ' . $mensagem);
                }
                if ($erro instanceof IntegracaoErro && in_array($erro->getCode(), [0, 401, 429], true)) {
                    break;
                }
            }
        }
        return $tratados;
    }

    /** @param array<string,mixed> $ev */
    private function tratar(string $codigo, array $ev, string $token): void
    {
        $ifoodId = (string) ($ev['orderId'] ?? '');
        if ($ifoodId === '' || in_array($codigo, ['KEEPALIVE', 'INTEGRATED'], true)) {
            return;
        }
        $p = $this->porIfood($ifoodId);
        if ($p === null) {
            // Pedido que o sistema ainda nao tem (aviso PLACED perdido): busca e grava, exceto se ja acabou.
            if (in_array($codigo, ['CANCELLED', 'CONCLUDED', 'CANCELLATION_REQUEST_FAILED'], true)) {
                return;
            }
            $importado = (new ImportarPedidoIfood($this->db, $this->conta->idRestaurante))
                ->importar($this->conta->api()->pedido($token, $ifoodId), $this->conta->opcao('aceitar_automatico'));
            if ($importado['novo']) {
                IfoodAcoes::agendarEnvio($this->conta->idRestaurante);
            }
            $p = $this->porIfood($ifoodId);
            if ($p === null || $codigo === 'PLACED') {
                return;
            }
        }
        $id = (int) $p['id'];
        $entreguePorIfood = $p['ifood_entregue_por'] === 'ifood';
        switch ($codigo) {
            case 'PLACED':
                return;
            case 'CONFIRMED':
                $this->mudar($p, ['recebido'], 'em_preparo', 'Pedido aceito no iFood. Em preparo.');
                return;
            case 'READY_TO_PICKUP':
                $this->mudar($p, ['recebido', 'em_preparo'], 'pronto', 'Pedido pronto (informado ao iFood).');
                return;
            case 'DISPATCHED':
                $this->mudar($p, ['recebido', 'em_preparo', 'pronto'], 'saiu_entrega', $entreguePorIfood ? 'Saiu com o entregador do iFood.' : 'Saiu para entrega (informado ao iFood).', ['despachado_em' => date('Y-m-d H:i:s')]);
                if ($entreguePorIfood) {
                    $this->etapa($id, 'DISPATCHED', []);
                }
                return;
            case 'CONCLUDED':
                $this->concluir($p);
                return;
            case 'CANCELLED':
                $motivo = self::motivo($ev);
                if ((new CancelarPedido($this->db))->cancelar($this->conta->idRestaurante, $id, 'Cancelado no iFood' . ($motivo !== '' ? ': ' . $motivo : '.'))) {
                    DadosPedidoIfood::alterar($this->db, $this->conta->idRestaurante, $id, static function (array $d): array {
                        $d['cancelamento'] = 'confirmado';
                        return $d;
                    }, false);
                }
                return;
            case 'CANCELLATION_REQUESTED':
                DadosPedidoIfood::alterar($this->db, $this->conta->idRestaurante, $id, static function (array $d): array {
                    $d['cancelamento'] = 'solicitado';
                    return $d;
                });
                return;
            case 'CANCELLATION_REQUEST_FAILED':
                DadosPedidoIfood::alterar($this->db, $this->conta->idRestaurante, $id, static function (array $d): array {
                    $d['cancelamento'] = null;
                    $d['avisos'][] = 'O iFood não aceitou o cancelamento. Continue o pedido ou resolva no Gestor de Pedidos do iFood.';
                    return $d;
                });
                $this->pedidos->registrarEvento($this->conta->idRestaurante, $id, (string) $p['status'], 'O iFood recusou o cancelamento.');
                return;
            case 'ASSIGN_DRIVER':
            case 'GOING_TO_ORIGIN':
            case 'ARRIVED_AT_ORIGIN':
            case 'ARRIVED_AT_DESTINATION':
            case 'DELIVERY_DRIVER_DEALLOCATED':
                $this->etapa($id, $codigo, is_array($ev['metadata'] ?? null) ? $ev['metadata'] : []);
                return;
            case 'COLLECTED':
                $this->etapa($id, $codigo, is_array($ev['metadata'] ?? null) ? $ev['metadata'] : []);
                $this->mudar($this->porIfood($ifoodId) ?? $p, ['recebido', 'em_preparo', 'pronto'], 'saiu_entrega', 'O entregador do iFood pegou o pedido.', ['despachado_em' => date('Y-m-d H:i:s')]);
                return;
        }
        if (str_contains($codigo, 'CANCELLATION_REQUEST') || str_contains($codigo, 'HANDSHAKE_DISPUTE') || str_contains($codigo, 'DISPUTE')) {
            DadosPedidoIfood::adicionarAviso($this->db, $this->conta->idRestaurante, $id, 'O cliente pediu para cancelar ou reclamou do pedido no iFood. Responda pelo Gestor de Pedidos do iFood.');
        }
    }

    /**
     * Mudanca de status vinda do iFood (sem gerar acao de volta).
     * @param array<string,mixed> $p @param list<string> $de @param array<string,mixed> $extra
     */
    private function mudar(array $p, array $de, string $para, string $texto, array $extra = []): void
    {
        if (!in_array($p['status'], $de, true)) {
            return;
        }
        $sets = 'status = ?, atualizado_em = ?';
        $valores = [$para, date('Y-m-d H:i:s')];
        foreach ($extra as $coluna => $valor) {
            $sets .= ', ' . $coluna . ' = ?';
            $valores[] = $valor;
        }
        $marcadores = implode(',', array_fill(0, count($de), '?'));
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE pedidos SET $sets WHERE id = ? AND id_restaurante = ? AND status IN ($marcadores)");
            $u->execute([...$valores, (int) $p['id'], $this->conta->idRestaurante, ...$de]);
            if ($u->rowCount() === 1) {
                $this->pedidos->registrarEvento($this->conta->idRestaurante, (int) $p['id'], $para, $texto);
                TempoReal::registrar($this->db, $this->conta->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => (int) $p['id'], 'numero' => (int) $p['numero'], 'status' => $para]);
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
    }

    /** Concluido no iFood: conclui aqui e registra o pagamento (no caixa aberto, se houver). @param array<string,mixed> $p */
    private function concluir(array $p): void
    {
        if (in_array($p['status'], ['concluido', 'cancelado'], true)) {
            return;
        }
        $formas = ['ifood' => 'ifood', 'dinheiro' => 'dinheiro', 'debito' => 'debito', 'credito' => 'credito', 'pix_entrega' => 'pix'];
        $caixa = (new CaixaRepository($this->db))->aberto($this->conta->idRestaurante);
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE pedidos SET status = 'concluido', pagamento_status = 'pago', pago_em = COALESCE(pago_em, ?), concluido_em = ?, id_caixa = ?, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND status NOT IN ('concluido', 'cancelado')");
            $u->execute([$agora, $agora, $caixa !== null ? (int) $caixa['id'] : null, $agora, (int) $p['id'], $this->conta->idRestaurante]);
            if ($u->rowCount() === 1) {
                $forma = $formas[(string) $p['forma_pagamento']] ?? null;
                if ($forma !== null && (float) $p['total'] > 0) {
                    $this->db->prepare('INSERT INTO pedido_pagamentos (id_pedido, id_restaurante, id_caixa, forma, valor, troco, id_user, criado_em) VALUES (?, ?, ?, ?, ?, \'0.00\', NULL, ?)')
                        ->execute([(int) $p['id'], $this->conta->idRestaurante, $caixa !== null ? (int) $caixa['id'] : null, $forma, (string) $p['total'], $agora]);
                }
                $this->pedidos->registrarEvento($this->conta->idRestaurante, (int) $p['id'], 'concluido', 'Pedido concluído no iFood.');
                TempoReal::registrar($this->db, $this->conta->idRestaurante, 'pedidos', 'pedido.atualizado', ['id' => (int) $p['id'], 'numero' => (int) $p['numero'], 'status' => 'concluido']);
                if ($caixa !== null) {
                    TempoReal::registrar($this->db, $this->conta->idRestaurante, 'caixa', 'caixa.pagamento', ['caixa' => (int) $caixa['id']]);
                }
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
    }

    /** Guarda a etapa e os dados do entregador do iFood (nome, telefone, veiculo, foto). @param array<string,mixed> $meta */
    private function etapa(int $idPedido, string $codigo, array $meta): void
    {
        $texto = static function (array $m, array $chaves, int $max): ?string {
            foreach ($chaves as $c) {
                $v = $m[$c] ?? null;
                if (is_scalar($v) && trim((string) $v) !== '') {
                    return mb_substr(trim((string) $v), 0, $max);
                }
            }
            return null;
        };
        $fonte = is_array($meta['driver'] ?? null) ? $meta['driver'] + $meta : (is_array($meta['worker'] ?? null) ? $meta['worker'] + $meta : $meta);
        $nome = $texto($fonte, ['workerName', 'driverName', 'name'], 80);
        $telefone = $texto($fonte, ['workerPhone', 'driverPhone', 'phone'], 30);
        $foto = $texto($fonte, ['workerPhotoUrl', 'driverPhotoUrl', 'photoUrl', 'photo'], 300);
        $veiculo = $texto($fonte, ['workerVehicleType', 'vehicleType', 'vehicle'], 30);
        $foto = $foto !== null && str_starts_with($foto, 'https://') ? $foto : null;
        DadosPedidoIfood::alterar($this->db, $this->conta->idRestaurante, $idPedido, static function (array $d) use ($codigo, $nome, $telefone, $foto, $veiculo): array {
            $e = $codigo === 'DELIVERY_DRIVER_DEALLOCATED' ? [] : ($d['entregador'] ?? []);
            $e['nome'] = $nome ?? ($e['nome'] ?? null);
            $e['telefone'] = $telefone ?? ($e['telefone'] ?? null);
            $e['foto'] = $foto ?? ($e['foto'] ?? null);
            $e['veiculo'] = $veiculo ?? ($e['veiculo'] ?? null);
            $e['etapa'] = $codigo;
            $e['etapa_em'] = date('Y-m-d H:i:s');
            $d['entregador'] = $e;
            return $d;
        });
    }

    /** @param array<string,mixed> $ev */
    private static function motivo(array $ev): string
    {
        $m = is_array($ev['metadata'] ?? null) ? $ev['metadata'] : [];
        foreach (['CANCEL_REASON', 'reason', 'cancelReason', 'cancellationReason', 'description', 'message', 'CANCEL_STAGE'] as $chave) {
            $v = $m[$chave] ?? null;
            if (is_array($v)) {
                $v = $v['description'] ?? $v['message'] ?? null;
            }
            if (is_scalar($v) && trim((string) $v) !== '') {
                return mb_substr(trim((string) $v), 0, 150);
            }
        }
        return '';
    }

    /** @return array<string,mixed>|null */
    private function porIfood(string $ifoodId): ?array
    {
        $q = $this->db->prepare('SELECT * FROM pedidos WHERE id_restaurante = ? AND ifood_id = ?');
        $q->execute([$this->conta->idRestaurante, $ifoodId]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }

    private function marcar(int $id, ?string $erro, bool $encerrar): void
    {
        $this->db->prepare('UPDATE integracao_eventos SET tentativas = tentativas + 1, erro = ?, processado_em = ? WHERE id = ?')
            ->execute([$erro !== null ? mb_substr($erro, 0, 255) : null, $encerrar ? date('Y-m-d H:i:s') : null, $id]);
    }
}
