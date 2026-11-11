<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Caixa;

use PDO;

/** Mesas do salao e a comanda aberta de cada uma. */
final class MesaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Mesas (nao excluidas) com a comanda aberta, se houver.
     * @return list<array<string,mixed>>
     */
    public function listar(int $idRestaurante): array
    {
        $q = $this->db->prepare(
            "SELECT m.id, m.numero, m.apelido, m.lugares, m.ativo,
                    p.id AS comanda_id, p.numero AS comanda_numero, p.total AS comanda_total, p.criado_em AS comanda_desde, p.cliente_nome AS comanda_nome,
                    (SELECT COUNT(*) FROM pedido_itens i WHERE i.id_pedido = p.id AND i.id_restaurante = p.id_restaurante AND i.cancelado_em IS NULL) AS comanda_itens,
                    (SELECT COUNT(*) FROM pedidos h WHERE h.id_mesa = m.id AND h.id_restaurante = m.id_restaurante) AS historico
             FROM mesas m
             LEFT JOIN pedidos p ON p.id_mesa = m.id AND p.id_restaurante = m.id_restaurante AND p.status = 'aberto'
             WHERE m.id_restaurante = ? AND m.excluida_em IS NULL
             ORDER BY m.numero"
        );
        $q->execute([$idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM mesas WHERE id = ? AND id_restaurante = ? AND excluida_em IS NULL');
        $q->execute([$id, $idRestaurante]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
        return $m === false ? null : $m;
    }

    /** Inclusive mesas excluidas (para mostrar o nome em pedidos antigos). @return array<string,mixed>|null */
    public function buscarQualquer(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM mesas WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
        return $m === false ? null : $m;
    }

    public function numeroEmUso(int $idRestaurante, int $numero, ?int $exceto = null): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM mesas WHERE id_restaurante = ? AND numero = ? AND id <> ? LIMIT 1');
        $q->execute([$idRestaurante, $numero, $exceto ?? 0]);
        return $q->fetchColumn() !== false;
    }

    public function criar(int $idRestaurante, int $numero, ?string $apelido, ?int $lugares): int
    {
        $agora = date('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO mesas (id_restaurante, numero, apelido, lugares, ativo, criado_em, atualizado_em) VALUES (?, ?, ?, ?, 1, ?, ?)')
            ->execute([$idRestaurante, $numero, $apelido, $lugares, $agora, $agora]);
        $q = $this->db->prepare('SELECT id FROM mesas WHERE id_restaurante = ? AND numero = ?');
        $q->execute([$idRestaurante, $numero]);
        return (int) $q->fetchColumn();
    }

    public function atualizar(int $idRestaurante, int $id, int $numero, ?string $apelido, ?int $lugares, bool $ativo): void
    {
        $this->db->prepare('UPDATE mesas SET numero = ?, apelido = ?, lugares = ?, ativo = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ? AND excluida_em IS NULL')
            ->execute([$numero, $apelido, $lugares, $ativo ? 1 : 0, date('Y-m-d H:i:s'), $id, $idRestaurante]);
    }

    public function temComandaAberta(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare("SELECT 1 FROM pedidos WHERE id_mesa = ? AND id_restaurante = ? AND status = 'aberto' LIMIT 1");
        $q->execute([$id, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    public function temHistorico(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM pedidos WHERE id_mesa = ? AND id_restaurante = ? LIMIT 1');
        $q->execute([$id, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    /** Mesa com historico: some da lista e libera o numero, mas os pedidos antigos continuam ligados a ela. */
    public function arquivar(int $idRestaurante, int $id): void
    {
        $this->db->prepare("UPDATE mesas SET apelido = COALESCE(apelido, CONCAT('Mesa ', numero)), numero = NULL, ativo = 0, excluida_em = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id, $idRestaurante]);
    }

    public function excluir(int $idRestaurante, int $id): void
    {
        $this->db->prepare('DELETE FROM mesas WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
    }
}
