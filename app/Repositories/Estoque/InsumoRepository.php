<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use PDO;

/** Insumos (ingredientes e itens comprados prontos). Toda consulta filtra por id_restaurante. */
final class InsumoRepository
{
    private const CAMPOS = 'i.id, i.nome, i.unidade, i.estoque_atual, i.estoque_minimo, i.custo_unitario, i.ativo';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> com "usos" = quantos produtos usam o insumo */
    public function listar(int $idRestaurante): array
    {
        $query = $this->db->prepare(
            'SELECT ' . self::CAMPOS . ', COUNT(p.id) AS usos
             FROM insumos i
             LEFT JOIN produto_insumos pi ON pi.id_insumo = i.id AND pi.id_restaurante = i.id_restaurante
             LEFT JOIN produtos p ON p.id = pi.id_produto AND p.id_restaurante = pi.id_restaurante AND p.ativo = 1
             WHERE i.id_restaurante = ? AND i.excluido_em IS NULL
             GROUP BY ' . self::CAMPOS . '
             ORDER BY i.nome'
        );
        $query->execute([$idRestaurante]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM insumos i WHERE i.id = ? AND i.id_restaurante = ? AND i.excluido_em IS NULL');
        $query->execute([$id, $idRestaurante]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<int,array{id:int,nome:string,unidade:string,ativo:bool,custo:?string}> insumos para ficha tecnica, por id */
    public function mapaParaFicha(int $idRestaurante): array
    {
        $query = $this->db->prepare('SELECT id, nome, unidade, ativo, custo_unitario FROM insumos WHERE id_restaurante = ? AND excluido_em IS NULL ORDER BY nome');
        $query->execute([$idRestaurante]);
        $mapa = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapa[(int) $row['id']] = ['id' => (int) $row['id'], 'nome' => (string) $row['nome'], 'unidade' => (string) $row['unidade'], 'ativo' => (int) $row['ativo'] === 1, 'custo' => $row['custo_unitario'] === null ? null : (string) $row['custo_unitario']];
        }
        return $mapa;
    }

    /** @return list<string> nomes dos produtos que usam o insumo */
    public function produtosQueUsam(int $idRestaurante, int $id): array
    {
        $query = $this->db->prepare(
            'SELECT p.nome FROM produto_insumos pi
             JOIN produtos p ON p.id = pi.id_produto AND p.id_restaurante = pi.id_restaurante
             WHERE pi.id_insumo = ? AND pi.id_restaurante = ? ORDER BY p.nome'
        );
        $query->execute([$id, $idRestaurante]);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Products still offered for sale that will be affected when this ingredient is archived. @return list<string> */
    public function produtosAtivosQueUsam(int $idRestaurante, int $id): array
    {
        $q = $this->db->prepare('SELECT p.nome FROM produto_insumos pi
            JOIN produtos p ON p.id = pi.id_produto AND p.id_restaurante = pi.id_restaurante
            WHERE pi.id_insumo = ? AND pi.id_restaurante = ? AND p.ativo = 1 ORDER BY p.nome');
        $q->execute([$id, $idRestaurante]);
        return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function nomeEmUso(int $idRestaurante, string $nome, ?int $ignorarId = null): bool
    {
        $query = $this->db->prepare('SELECT id FROM insumos WHERE id_restaurante = ? AND excluido_em IS NULL AND LOWER(nome) = LOWER(?)');
        $query->execute([$idRestaurante, $nome]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $ignorarId) {
                return true;
            }
        }
        return false;
    }

    /** @param array{nome:string,unidade:string,estoque_atual:string,estoque_minimo:string,custo_unitario:?string,ativo:int} $d */
    public function criar(int $idRestaurante, array $d): int
    {
        $this->db->prepare(
            'INSERT INTO insumos (id_restaurante, nome, unidade, estoque_atual, estoque_minimo, custo_unitario, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$idRestaurante, $d['nome'], $d['unidade'], $d['estoque_atual'], $d['estoque_minimo'], $d['custo_unitario'], $d['ativo']]);
        $id = $this->db->prepare('SELECT id FROM insumos WHERE id_restaurante = ? AND excluido_em IS NULL AND nome = ?');
        $id->execute([$idRestaurante, $d['nome']]);
        return (int) $id->fetchColumn();
    }

    /** @param array{nome:string,unidade:string,estoque_atual:string,estoque_minimo:string,custo_unitario:?string,ativo:int} $d */
    public function atualizar(int $idRestaurante, int $id, array $d): void
    {
        $this->db->prepare(
            'UPDATE insumos SET nome = ?, unidade = ?, estoque_atual = ?, estoque_minimo = ?, custo_unitario = ?, ativo = ?
             WHERE id = ? AND id_restaurante = ? AND excluido_em IS NULL'
        )->execute([$d['nome'], $d['unidade'], $d['estoque_atual'], $d['estoque_minimo'], $d['custo_unitario'], $d['ativo'], $id, $idRestaurante]);
    }

    public function definirEstoque(int $idRestaurante, int $id, string $novoEstoque): void
    {
        $this->db->prepare('UPDATE insumos SET estoque_atual = ? WHERE id = ? AND id_restaurante = ?')->execute([$novoEstoque, $id, $idRestaurante]);
    }

    /** Arquiva sem quebrar fichas tecnicas, estoque ou historico. Libera o nome para outro cadastro. */
    public function arquivar(int $idRestaurante, int $id, string $nome): void
    {
        $sufixo = ' (excluido ' . $id . ')';
        $nomeArquivado = mb_substr($nome, 0, 120 - mb_strlen($sufixo)) . $sufixo;
        $this->db->prepare('UPDATE insumos SET nome = ?, ativo = 0, excluido_em = CURRENT_TIMESTAMP
            WHERE id = ? AND id_restaurante = ? AND excluido_em IS NULL')
            ->execute([$nomeArquivado, $id, $idRestaurante]);
    }
}
