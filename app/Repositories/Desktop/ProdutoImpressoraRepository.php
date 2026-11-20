<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Desktop;

use PDO;

/**
 * Em quais impressoras (apelidos do Agent) cada produto sai na via de producao.
 * Toda gravacao confere que produto e impressora sao do mesmo restaurante e que a impressora esta ativa.
 */
final class ProdutoImpressoraRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<int> impressoras ativas marcadas no produto */
    public function doProduto(int $idRestaurante, int $idProduto): array
    {
        return $this->dosProdutos($idRestaurante, [$idProduto])[$idProduto] ?? [];
    }

    /**
     * @param list<int> $idsProdutos
     * @return array<int,list<int>> id_produto => impressoras ativas
     */
    public function dosProdutos(int $idRestaurante, array $idsProdutos): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsProdutos), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $q = $this->db->prepare('SELECT pi.id_produto, pi.id_impressora FROM produto_impressoras pi
            JOIN impressoras i ON i.id = pi.id_impressora AND i.id_restaurante = pi.id_restaurante AND i.ativo = 1
            WHERE pi.id_restaurante = ? AND pi.id_produto IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY i.apelido, i.id');
        $q->execute([$idRestaurante, ...$ids]);
        $mapa = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapa[(int) $r['id_produto']][] = (int) $r['id_impressora'];
        }
        return $mapa;
    }

    /** @param list<int> $idsImpressoras */
    public function salvar(int $idRestaurante, int $idProduto, array $idsImpressoras): void
    {
        $this->aplicar($idRestaurante, [$idProduto], $idsImpressoras);
    }

    /**
     * Atalho da categoria: grava as mesmas impressoras em todos os produtos dela. Devolve quantos produtos mudaram.
     * @param list<int> $idsImpressoras
     */
    public function aplicarCategoria(int $idRestaurante, int $idCategoria, array $idsImpressoras): int
    {
        $q = $this->db->prepare('SELECT id FROM produtos WHERE id_restaurante = ? AND id_categoria = ?');
        $q->execute([$idRestaurante, $idCategoria]);
        $produtos = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $this->aplicar($idRestaurante, $produtos, $idsImpressoras);
        return count($produtos);
    }

    /**
     * Resumo para a tela de categorias: impressoras em comum de todos os produtos de cada categoria
     * (null = produtos com impressoras diferentes).
     * @return array<int,list<int>|null>
     */
    public function resumoCategorias(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT id, id_categoria FROM produtos WHERE id_restaurante = ? AND id_categoria IS NOT NULL');
        $q->execute([$idRestaurante]);
        $porCategoria = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porCategoria[(int) $r['id_categoria']][] = (int) $r['id'];
        }
        $mapa = $this->dosProdutos($idRestaurante, array_merge([], ...array_values($porCategoria)));
        $saida = [];
        foreach ($porCategoria as $cat => $produtos) {
            $primeiro = $mapa[$produtos[0]] ?? [];
            $igual = true;
            foreach ($produtos as $p) {
                if (($mapa[$p] ?? []) !== $primeiro) {
                    $igual = false;
                    break;
                }
            }
            $saida[$cat] = $igual ? $primeiro : null;
        }
        return $saida;
    }

    /** @param list<int> $produtos @param list<int> $idsImpressoras */
    private function aplicar(int $idRestaurante, array $produtos, array $idsImpressoras): void
    {
        $validas = array_column((new ImpressoraRepository($this->db))->listar($idRestaurante), 'id');
        $ids = array_values(array_intersect(array_unique(array_map('intval', $idsImpressoras)), $validas));
        $propria = !$this->db->inTransaction();
        if ($propria) {
            $this->db->beginTransaction();
        }
        try {
            $apaga = $this->db->prepare('DELETE FROM produto_impressoras WHERE id_restaurante = ? AND id_produto = ?');
            $grava = $this->db->prepare('INSERT INTO produto_impressoras (id_restaurante, id_produto, id_impressora) VALUES (?, ?, ?)');
            foreach ($produtos as $idProduto) {
                $apaga->execute([$idRestaurante, $idProduto]);
                foreach ($ids as $id) {
                    $grava->execute([$idRestaurante, $idProduto, $id]);
                }
            }
            if ($propria) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($propria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
