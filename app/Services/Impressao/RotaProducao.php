<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Repositories\Desktop\ProdutoImpressoraRepository;
use Cardapim\Shared\Esquema;
use PDO;

/**
 * Regra UNICA de para onde vai cada item na via de producao (impressao real, aviso de cancelamento e o
 * "Testar impressao" da gerencia usam esta mesma funcao, para o teste ser fiel):
 * - produto marcado "Nao imprime" fica fora de todas as impressoras;
 * - produto com impressoras marcadas sai nelas;
 * - o resto (sem marcacao, opcao de combo sem produto, item do iFood nao ligado) sai na padrao da producao.
 */
final class RotaProducao
{
    /**
     * @param list<array<string,mixed>> $itens linhas no formato de pedido_itens (id_produto pode ser null)
     * @return array{grupos:array<int,list<array<string,mixed>>>, porProduto:array<int,list<int>>, naoImprime:array<int,true>, fora:list<array<string,mixed>>}
     *         grupos = id_impressora => itens; fora = itens que nao saem em lugar nenhum
     */
    public static function dividir(PDO $db, int $idRestaurante, array $itens, int $padrao): array
    {
        $ids = array_map(static fn (array $i): int => (int) ($i['id_produto'] ?? 0), $itens);
        $naoImprime = self::naoImprimem($db, $idRestaurante, $ids);
        $imprimir = [];
        $fora = [];
        foreach ($itens as $i) {
            if (isset($naoImprime[(int) ($i['id_produto'] ?? 0)])) {
                $fora[] = $i;
            } else {
                $imprimir[] = $i;
            }
        }
        $porProduto = (new ProdutoImpressoraRepository($db))->dosProdutos($idRestaurante, $ids);
        return ['grupos' => CupomImpressao::dividir($imprimir, $porProduto, $padrao), 'porProduto' => $porProduto, 'naoImprime' => $naoImprime, 'fora' => $fora];
    }

    /**
     * Produtos marcados "Nao imprime" (entre os informados).
     * @param list<int> $idsProdutos
     * @return array<int,true>
     */
    public static function naoImprimem(PDO $db, int $idRestaurante, array $idsProdutos): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsProdutos), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || !Esquema::tem($db, 'produto_opcoes')) {
            return [];
        }
        $q = $db->prepare('SELECT id FROM produtos WHERE id_restaurante = ? AND imprime_producao = 0 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([$idRestaurante, ...$ids]);
        return array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
