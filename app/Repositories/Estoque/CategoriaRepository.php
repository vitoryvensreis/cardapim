<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use PDO;

/** Categorias do cardapio. Toda consulta filtra por id_restaurante. */
final class CategoriaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{id:int,nome:string,ordem:int,produtos:int}> */
    public function listar(int $idRestaurante): array
    {
        $query = $this->db->prepare(
            'SELECT c.id, c.nome, c.ordem, COUNT(p.id) AS produtos
             FROM categorias c
             LEFT JOIN produtos p ON p.id_categoria = c.id AND p.id_restaurante = c.id_restaurante
             WHERE c.id_restaurante = ?
             GROUP BY c.id, c.nome, c.ordem
             ORDER BY c.ordem, c.nome'
        );
        $query->execute([$idRestaurante]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'ordem' => (int) $r['ordem'], 'produtos' => (int) $r['produtos'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id:int,nome:string}|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, nome FROM categorias WHERE id = ? AND id_restaurante = ?');
        $query->execute([$id, $idRestaurante]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'nome' => (string) $row['nome']];
    }

    public function nomeEmUso(int $idRestaurante, string $nome, ?int $ignorarId = null): bool
    {
        $query = $this->db->prepare('SELECT id FROM categorias WHERE id_restaurante = ? AND LOWER(nome) = LOWER(?)');
        $query->execute([$idRestaurante, $nome]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $ignorarId) {
                return true;
            }
        }
        return false;
    }

    public function criar(int $idRestaurante, string $nome): int
    {
        $ordem = $this->db->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM categorias WHERE id_restaurante = ?');
        $ordem->execute([$idRestaurante]);
        $this->db->prepare('INSERT INTO categorias (id_restaurante, nome, ordem) VALUES (?, ?, ?)')
            ->execute([$idRestaurante, $nome, min(65535, (int) $ordem->fetchColumn())]);
        $id = $this->db->prepare('SELECT id FROM categorias WHERE id_restaurante = ? AND nome = ?');
        $id->execute([$idRestaurante, $nome]);
        return (int) $id->fetchColumn();
    }

    public function renomear(int $idRestaurante, int $id, string $nome): void
    {
        $this->db->prepare('UPDATE categorias SET nome = ? WHERE id = ? AND id_restaurante = ?')->execute([$nome, $id, $idRestaurante]);
    }

    /** Remove a categoria; os produtos dela ficam "sem categoria". Chamar dentro de transacao. */
    public function excluir(int $idRestaurante, int $id): int
    {
        $produtos = $this->db->prepare('UPDATE produtos SET id_categoria = NULL WHERE id_categoria = ? AND id_restaurante = ?');
        $produtos->execute([$id, $idRestaurante]);
        $this->db->prepare('DELETE FROM categorias WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
        return $produtos->rowCount();
    }
}
