<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Catalog\Combo;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Linhas da sacola do cardapio online, conferidas com os precos e as porcoes de AGORA (o navegador manda so
 * ids, quantidades, a porcao e os complementos escolhidos). Cada linha: {id, quantidade (1 a 99), porcao (milesimos, so
 * produto por quilo/litro), observacao, complementos [{id, quantidade}]}. Produto por peso so aceita uma porcao cadastrada
 * pelo gerente. Complementos conferidos pela regra unica (Modules/Catalog/Complementos): preco unitario = base + complementos.
 * Valor da linha = preco do quilo x (quantidade x porcao), arredondado ao centavo (mesma conta do navegador).
 */
final class LinhasSacola
{
    /**
     * Le e valida o formato (sem banco).
     * @return list<array{id:int, quantidade:int, porcao:?int, observacao:string, complementos:list<array{id:int,quantidade:int}>}>|null null = formato invalido
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
            $complementos = Complementos::formato($item['complementos'] ?? null);
            if ($complementos === null) {
                return null;
            }
            $linhas[] = ['id' => $id, 'quantidade' => $qtd, 'porcao' => $porcao, 'observacao' => $obs, 'complementos' => $complementos];
        }
        return $linhas;
    }

    /**
     * Completa as linhas com nome, unidade, preco, quantidade vendida (milesimos) e valor.
     * Produto que nao existe mais fica em 'ausentes'; porcao que nao existe (ou porcao em produto por unidade)
     * deixa a linha invalida.
     * Complemento que nao pode ser escolhido (sumiu, acabou, fora do minimo/maximo) tambem deixa a linha invalida, com 'mensagem'.
     * @param list<array{id:int, quantidade:int, porcao:?int, observacao:string, complementos?:list<array{id:int,quantidade:int}>}> $linhas
     * @return array{linhas: list<array<string,mixed>>, ausentes: list<int>, invalida: bool, mensagem: ?string}
     */
    public static function completar(PDO $db, int $idRestaurante, array $linhas): array
    {
        $ids = array_values(array_unique(array_column($linhas, 'id')));
        if ($ids === []) {
            return ['linhas' => [], 'ausentes' => [], 'invalida' => false, 'mensagem' => null];
        }
        // Cardapio online: produto "so no restaurante" fica de fora (vira ausente na sacola).
        $presencial = \Cardapim\Shared\Esquema::tem($db, 'produto_opcoes') ? ' AND vende_delivery = 1' : '';
        $q = $db->prepare('SELECT id, nome, preco, unidade_venda FROM produtos WHERE id_restaurante = ? AND ativo = 1' . $presencial . ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([$idRestaurante, ...$ids]);
        $produtos = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $produtos[(int) $p['id']] = $p;
        }
        $porcoes = (new CardapioPublico($db))->porcoes($idRestaurante, $ids);
        $grupos = Complementos::carregar($db, $idRestaurante, $ids);
        $combos = Combo::carregar($db, $idRestaurante, $ids);
        $saida = [];
        $invalida = false;
        $mensagem = null;
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
            $escolhas = $l['complementos'] ?? [];
            // Combo: confere as escolhas pela regra do combo; ao gravar o pedido vira os itens separados (CriarPedido).
            $combo = $unidade === 'un' ? ($combos[$l['id']] ?? null) : null;
            if ($combo !== null) {
                $conf = Combo::conferir($combo, $escolhas);
                if (!$conf['ok']) {
                    $invalida = true;
                    $mensagem ??= $p['nome'] . ': ' . $conf['erro'];
                    continue;
                }
                $unitario = $centavos + $conf['extras'];
                $saida[] = array_replace($l, [
                    'nome' => (string) $p['nome'],
                    'unidade' => $unidade,
                    'centavos' => $unitario,
                    'milesimos' => $milesimos,
                    'total' => $unitario * $l['quantidade'],
                    'complementos' => [],
                    'complementos_texto' => $conf['texto'],
                    'insumos_complementos' => [],
                    'combo' => Combo::montar($combo, $conf['escolhidos'], $centavos, $l['quantidade']),
                ]);
                continue;
            }
            $gruposProduto = $unidade === 'un' ? ($grupos[$l['id']] ?? []) : [];
            $conf = ['ok' => true, 'centavos' => 0, 'itens' => [], 'texto' => '', 'insumos' => [], 'produtos' => []];
            if ($gruposProduto !== [] || $escolhas !== []) {
                $conf = Complementos::conferir($gruposProduto, $escolhas);
                if (!$conf['ok']) {
                    $invalida = true;
                    $mensagem ??= $p['nome'] . ': ' . $conf['erro'];
                    continue;
                }
            }
            $unitario = $centavos + $conf['centavos'];
            // array_replace: 'complementos' passa a ser a copia conferida (grupo, nome, preco), no lugar dos ids enviados.
            $saida[] = array_replace($l, [
                'nome' => (string) $p['nome'],
                'unidade' => $unidade,
                'centavos' => $unitario,
                'milesimos' => $milesimos,
                'total' => QuantidadeVenda::totalCentavos($unitario, $milesimos),
                'complementos' => $conf['itens'],
                'complementos_texto' => $conf['texto'],
                'insumos_complementos' => Complementos::consumoDaLinha($conf['insumos'], intdiv($milesimos, 1000)),
                // Meio a Meio: fracao de cada sabor (milesimos do produto sabor) x unidades.
                'sabores' => Complementos::consumoDaLinha($conf['produtos'] ?? [], intdiv($milesimos, 1000)),
            ]);
        }
        return ['linhas' => $saida, 'ausentes' => array_values(array_diff($ids, array_keys($produtos))), 'invalida' => $invalida, 'mensagem' => $mensagem];
    }
}
