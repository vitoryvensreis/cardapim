<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\PedidoRepository;
use PDO;
use Throwable;

/** Cancela o pedido e devolve ao estoque o que foi baixado. Tudo na mesma transacao; repetir nao devolve duas vezes. */
final class CancelarPedido
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function cancelar(int $idRestaurante, int $idPedido, string $motivo, ?int $idUser = null, ?int $idCliente = null): bool
    {
        $this->db->beginTransaction();
        try {
            $agora = date('Y-m-d H:i:s');
            $q = $this->db->prepare("UPDATE pedidos SET status = 'cancelado', cancelado_em = ?, motivo_cancelamento = ?,
                pagamento_status = CASE WHEN pagamento_status = 'pendente' THEN 'cancelado' ELSE pagamento_status END, atualizado_em = ?
                WHERE id = ? AND id_restaurante = ? AND status NOT IN ('cancelado', 'concluido')");
            $q->execute([$agora, mb_substr($motivo, 0, 200), $agora, $idPedido, $idRestaurante]);
            if ($q->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            $pedido = (new PedidoRepository($this->db))->buscar($idRestaurante, $idPedido) ?? [];
            $consumo = json_decode((string) ($pedido['consumo_estoque'] ?? ''), true);
            if (is_array($consumo) && $consumo !== []) {
                (new BaixaEstoque($this->db))->devolver($idRestaurante, array_map('strval', $consumo));
                $this->db->prepare('UPDATE pedidos SET consumo_estoque = NULL WHERE id = ? AND id_restaurante = ?')->execute([$idPedido, $idRestaurante]);
            }
            (new PedidoRepository($this->db))->registrarEvento($idRestaurante, $idPedido, 'cancelado', $motivo, $idUser, $idCliente);
            TempoReal::registrar($this->db, $idRestaurante, 'pedidos', 'pedido.cancelado', ['id' => $idPedido, 'numero' => (int) $pedido['numero']]);
            $this->db->commit();
            return true;
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }
}
