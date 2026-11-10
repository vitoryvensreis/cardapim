<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/** Leitura e gravacao de pedidos. Toda consulta filtra pelo restaurante (e pelo cliente, quando e o cliente quem pede). */
final class PedidoRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $idPedido): ?array
    {
        $q = $this->db->prepare('SELECT * FROM pedidos WHERE id = ? AND id_restaurante = ?');
        $q->execute([$idPedido, $idRestaurante]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }

    /** Pedido do cliente (qualquer restaurante). @return array<string,mixed>|null */
    public function doCliente(int $idCliente, int $idPedido): ?array
    {
        $q = $this->db->prepare('SELECT * FROM pedidos WHERE id = ? AND id_cliente = ?');
        $q->execute([$idPedido, $idCliente]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }

    /** @return array<string,mixed>|null */
    public function porChave(int $idRestaurante, string $chave): ?array
    {
        $q = $this->db->prepare('SELECT * FROM pedidos WHERE id_restaurante = ? AND chave_idempotencia = ?');
        $q->execute([$idRestaurante, $chave]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }

    /** @return array<string,mixed>|null */
    public function porCobranca(int $idRestaurante, string $idCobranca): ?array
    {
        $q = $this->db->prepare('SELECT * FROM pedidos WHERE id_restaurante = ? AND pix_cobranca_id = ?');
        $q->execute([$idRestaurante, $idCobranca]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        return $p === false ? null : $p;
    }

    /** @return list<array<string,mixed>> */
    public function itens(int $idRestaurante, int $idPedido): array
    {
        $q = $this->db->prepare('SELECT id_produto, nome, preco_unitario, quantidade, observacao, total FROM pedido_itens WHERE id_pedido = ? AND id_restaurante = ? ORDER BY id');
        $q->execute([$idPedido, $idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function eventos(int $idRestaurante, int $idPedido): array
    {
        $q = $this->db->prepare('SELECT status, descricao, criado_em FROM pedido_eventos WHERE id_pedido = ? AND id_restaurante = ? ORDER BY id');
        $q->execute([$idPedido, $idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarEvento(int $idRestaurante, int $idPedido, string $status, string $descricao, ?int $idUser = null, ?int $idCliente = null): void
    {
        $this->db->prepare('INSERT INTO pedido_eventos (id_pedido, id_restaurante, status, descricao, id_user, id_cliente, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idPedido, $idRestaurante, $status, mb_substr($descricao, 0, 200), $idUser, $idCliente, date('Y-m-d H:i:s')]);
    }

    /** Ultimos pedidos do cliente em um restaurante (para "Meus pedidos"). @return list<array<string,mixed>> */
    public function recentesDoCliente(int $idCliente, int $idRestaurante, int $limite = 5): array
    {
        $q = $this->db->prepare('SELECT id, numero, status, total, criado_em FROM pedidos WHERE id_cliente = ? AND id_restaurante = ? ORDER BY id DESC LIMIT ' . max(1, min(20, $limite)));
        $q->execute([$idCliente, $idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
