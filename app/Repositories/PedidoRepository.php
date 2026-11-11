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

    /**
     * Historico do cliente (mais novo primeiro), em um restaurante ou em todos. $antes = id do ultimo da pagina anterior.
     * Nunca inclui comandas de mesa/balcao (status 'aberto' nao tem cliente). @return list<array<string,mixed>>
     */
    public function historicoDoCliente(int $idCliente, ?int $idRestaurante, ?int $antes, int $limite): array
    {
        $sql = "SELECT p.id, p.numero, p.status, p.tipo, p.total, p.criado_em, p.id_restaurante, r.nome AS restaurante_nome, r.slug AS restaurante_slug
                FROM pedidos p JOIN restaurantes r ON r.id = p.id_restaurante
                WHERE p.id_cliente = ? AND p.status <> 'aberto'";
        $params = [$idCliente];
        if ($idRestaurante !== null) {
            $sql .= ' AND p.id_restaurante = ?';
            $params[] = $idRestaurante;
        }
        if ($antes !== null) {
            $sql .= ' AND p.id < ?';
            $params[] = $antes;
        }
        $q = $this->db->prepare($sql . ' ORDER BY p.id DESC LIMIT ' . max(1, min(51, $limite)));
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pedidos do cliente ainda em andamento neste restaurante (ultimas 24 h). @return list<array<string,mixed>> */
    public function emAndamentoDoCliente(int $idCliente, int $idRestaurante): array
    {
        $q = $this->db->prepare("SELECT p.id, p.numero, p.status, p.tipo, p.total, p.criado_em, p.id_restaurante, r.nome AS restaurante_nome, r.slug AS restaurante_slug
            FROM pedidos p JOIN restaurantes r ON r.id = p.id_restaurante
            WHERE p.id_cliente = ? AND p.id_restaurante = ? AND p.status NOT IN ('aberto', 'concluido', 'cancelado') AND p.criado_em >= ?
            ORDER BY p.id DESC LIMIT 3");
        $q->execute([$idCliente, $idRestaurante, date('Y-m-d H:i:s', time() - 86400)]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Itens (nao cancelados) de varios pedidos, agrupados por pedido. @param list<int> $ids @return array<int,list<array{nome:string,quantidade:int}>> */
    public function itensResumo(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $q = $this->db->prepare('SELECT id_pedido, nome, quantidade FROM pedido_itens WHERE id_pedido IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND cancelado_em IS NULL ORDER BY id');
        $q->execute($ids);
        $por = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $por[(int) $i['id_pedido']][] = ['nome' => (string) $i['nome'], 'quantidade' => (int) $i['quantidade']];
        }
        return $por;
    }
}
