<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use Cardapim\Shared\Esquema;
use PDO;

/** Insumos (ingredientes e itens comprados prontos). Toda consulta filtra por id_restaurante. */
final class InsumoRepository
{
    private const CAMPOS = 'i.id, i.nome, i.unidade, i.estoque_atual, i.estoque_minimo, i.custo_unitario, i.ativo';

    public function __construct(private readonly PDO $db)
    {
    }

    /** Insumos proprios de produto (estoque contado no produto) nao aparecem na lista nem nas fichas. */
    private function semProprios(string $alias = 'i'): string
    {
        return Esquema::tem($this->db, 'estoque_produto') ? ' AND ' . $alias . '.id_produto_proprio IS NULL' : '';
    }

    /**
     * @param bool $comProprios true = inclui o estoque contado nos produtos (resumo e alertas); a aba Insumos nao mostra
     * @return list<array<string,mixed>> com "usos" = quantos produtos usam o insumo e "id_produto_proprio" (null = insumo comum)
     */
    public function listar(int $idRestaurante, bool $comProprios = false): array
    {
        $proprio = Esquema::tem($this->db, 'estoque_produto');
        $query = $this->db->prepare(
            'SELECT ' . self::CAMPOS . ', ' . ($proprio ? 'i.id_produto_proprio' : 'NULL AS id_produto_proprio') . ', COUNT(p.id) AS usos
             FROM insumos i
             LEFT JOIN produto_insumos pi ON pi.id_insumo = i.id AND pi.id_restaurante = i.id_restaurante
             LEFT JOIN produtos p ON p.id = pi.id_produto AND p.id_restaurante = pi.id_restaurante AND p.ativo = 1
             WHERE i.id_restaurante = ? AND i.excluido_em IS NULL' . ($comProprios ? '' : $this->semProprios()) . '
             GROUP BY ' . self::CAMPOS . ($proprio ? ', i.id_produto_proprio' : '') . '
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
        $query = $this->db->prepare('SELECT id, nome, unidade, ativo, custo_unitario FROM insumos i WHERE id_restaurante = ? AND excluido_em IS NULL'
            . $this->semProprios() . ' ORDER BY nome');
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

    /**
     * Insumo proprio do produto (estoque contado no produto), se houver.
     * @return array{id:int,nome:string,unidade:string,estoque_atual:string,estoque_minimo:string,custo_unitario:?string}|null
     */
    public function doProduto(int $idRestaurante, int $idProduto): ?array
    {
        if (!Esquema::tem($this->db, 'estoque_produto')) {
            return null;
        }
        $q = $this->db->prepare('SELECT id, nome, unidade, estoque_atual, estoque_minimo, custo_unitario FROM insumos
            WHERE id_restaurante = ? AND id_produto_proprio = ? AND excluido_em IS NULL');
        $q->execute([$idRestaurante, $idProduto]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'unidade' => (string) $r['unidade'],
            'estoque_atual' => (string) $r['estoque_atual'], 'estoque_minimo' => (string) $r['estoque_minimo'],
            'custo_unitario' => $r['custo_unitario'] === null ? null : (string) $r['custo_unitario']];
    }

    /** Nome do produto dono do insumo proprio (null = insumo comum). */
    public function produtoDono(int $idRestaurante, int $idInsumo): ?array
    {
        if (!Esquema::tem($this->db, 'estoque_produto')) {
            return null;
        }
        $q = $this->db->prepare('SELECT p.id, p.nome FROM insumos i JOIN produtos p ON p.id = i.id_produto_proprio AND p.id_restaurante = i.id_restaurante
            WHERE i.id = ? AND i.id_restaurante = ?');
        $q->execute([$idInsumo, $idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['id' => (int) $r['id'], 'nome' => (string) $r['nome']];
    }

    /** @param array{nome:string,unidade:string,estoque_atual:string,estoque_minimo:string,custo_unitario:?string} $d */
    public function criarDoProduto(int $idRestaurante, int $idProduto, array $d): int
    {
        $this->db->prepare(
            'INSERT INTO insumos (id_restaurante, nome, unidade, estoque_atual, estoque_minimo, custo_unitario, ativo, id_produto_proprio)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$idRestaurante, $d['nome'], $d['unidade'], $d['estoque_atual'], $d['estoque_minimo'], $d['custo_unitario'], $idProduto]);
        $q = $this->db->prepare('SELECT id FROM insumos WHERE id_restaurante = ? AND id_produto_proprio = ? AND excluido_em IS NULL');
        $q->execute([$idRestaurante, $idProduto]);
        return (int) $q->fetchColumn();
    }

    /** Arquiva sem quebrar fichas tecnicas, estoque ou historico. Libera o nome para outro cadastro. */
    public function arquivar(int $idRestaurante, int $id, string $nome): void
    {
        $sufixo = ' (excluido ' . $id . ')';
        $nomeArquivado = mb_substr($nome, 0, 120 - mb_strlen($sufixo)) . $sufixo;
        $this->db->prepare('UPDATE insumos SET nome = ?, ativo = 0, excluido_em = CURRENT_TIMESTAMP
            WHERE id = ? AND id_restaurante = ? AND excluido_em IS NULL')
            ->execute([$nomeArquivado, $id, $idRestaurante]);
        if (Esquema::tem($this->db, 'estoque_produto')) {
            // Insumo proprio arquivado deixa de ser "do produto": o produto pode ganhar outro depois.
            $this->db->prepare('UPDATE insumos SET id_produto_proprio = NULL WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
        }
    }
}
