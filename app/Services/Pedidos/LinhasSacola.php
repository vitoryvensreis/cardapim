<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Linhas da sacola do cardapio online, conferidas com os precos e as porcoes de AGORA (o navegador manda so
 * ids, quantidades e a porcao escolhida). Cada linha: {id, quantidade (1 a 99), porcao (milesimos, so produto por
 * quilo/litro), observacao}. Produto por peso so aceita uma porcao cadastrada pelo gerente.
 * Valor da linha = preco do quilo x (quantidade x porcao), arredondado ao centavo (mesma conta do navegador).
 */
final class LinhasSacola
{
    /**
     * Le e valida o formato (sem banco).
     * @return list<array{id:int, quantidade:int, porcao:?int, observacao:string}>|null null = formato invalido
     */
    public static function formato(mixed $itens, int $max): ?array
    {
        $linhas = [];
        foreach (array_slice(is_array($itens) ? $itens : [], 0, $max + 1) as $item) {
            if (!is_array($item)) {
                return null;
            }
            $id = $item['id'] ?? null;
            $qtd = $item['quantidade'] ?? null;
            $porcao = $item['porcao'] ?? null;
            if (!is_int($id) || $id <= 0 || !is_int($qtd) || $qtd < 1 || $qtd > QuantidadeVenda::MAX_UNIDADES
                || ($porcao !== null && (!is_int($porcao) || $porcao < 1 || $porcao > QuantidadeVenda::MAX_FRACAO))) {
                return null;
            }
            $obs = is_string($item['observacao'] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $item['observacao'])), 0, 140) : '';
            $linhas[] = ['id' => $id, 'quantidade' => $qtd, 'porcao' => $porcao, 'observacao' => $obs];
        }
        return $linhas;
    }

    /**
     * Completa as linhas com nome, unidade, preco, quantidade vendida (milesimos) e valor.
     * Produto que nao existe mais fica em 'ausentes'; porcao que nao existe (ou porcao em produto por unidade)
     * deixa a linha invalida.
     * @param list<array{id:int, quantidade:int, porcao:?int, observacao:string}> $linhas
     * @return array{linhas: list<array<string,mixed>>, ausentes: list<int>, invalida: bool}
     */
    public static function completar(PDO $db, int $idRestaurante, array $linhas): array
    {
        $ids = array_values(array_unique(array_column($linhas, 'id')));
        if ($ids === []) {
            return ['linhas' => [], 'ausentes' => [], 'invalida' => false];
        }
        $q = $db->prepare('SELECT id, nome, preco, unidade_venda FROM produtos WHERE id_restaurante = ? AND ativo = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([$idRestaurante, ...$ids]);
        $produtos = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $produtos[(int) $p['id']] = $p;
        }
        $porcoes = (new CardapioPublico($db))->porcoes($idRestaurante, $ids);
        $saida = [];
        $invalida = false;
        foreach ($linhas as $l) {
            $p = $produtos[$l['id']] ?? null;
            if ($p === null) {
                continue;
            }
            $unidade = QuantidadeVenda::unidade($p['unidade_venda'] ?? 'un');
            if ($unidade === 'un') {
                if ($l['porcao'] !== null) {
                    $invalida = true;
                    continue;
                }
                $milesimos = $l['quantidade'] * 1000;
            } else {
                if ($l['porcao'] === null || !in_array($l['porcao'], $porcoes[$l['id']] ?? [], true)) {
                    $invalida = true;
                    continue;
                }
                $milesimos = $l['quantidade'] * $l['porcao'];
                if ($milesimos > QuantidadeVenda::MAX_FRACAO) {
                    $invalida = true;
                    continue;
                }
            }
            $centavos = Numero::centavos((string) $p['preco']);
            $saida[] = $l + [
                'nome' => (string) $p['nome'],
                'unidade' => $unidade,
                'centavos' => $centavos,
                'milesimos' => $milesimos,
                'total' => QuantidadeVenda::totalCentavos($centavos, $milesimos),
            ];
        }
        return ['linhas' => $saida, 'ausentes' => array_values(array_diff($ids, array_keys($produtos))), 'invalida' => $invalida];
    }
}
