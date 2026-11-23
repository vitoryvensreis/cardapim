<?php

declare(strict_types=1);

// Regressoes do agrupamento visual de comandas e do cupom: php tests/unit/agrupador-itens-comanda.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Caixa\AgrupadorItensComanda;
use Cardapim\Services\Impressao\CupomImpressao;

$falhas = 0;
$ok = static function (bool $passou, string $descricao) use (&$falhas): void {
    if (!$passou) {
        fwrite(STDERR, "FALHOU: {$descricao}\n");
        $falhas++;
    }
};
$item = static function (int $id, int $produto = 10, string $preco = '6.00', string $quantidade = '1.000', array $extra = []): array {
    return array_replace([
        'id' => $id, 'id_produto' => $produto, 'nome' => 'Cerveja Skol', 'preco_unitario' => $preco,
        'quantidade' => $quantidade, 'unidade' => 'un', 'total' => $preco === '6.00' ? ($quantidade === '2.000' ? '12.00' : '6.00') : $preco,
        'observacao' => null, 'complementos_texto' => null, 'combo_lote' => null, 'combo_nome' => null,
        'cancelado_em' => null, 'criado_em' => '2026-11-22 19:10:00', 'autor' => 'Joao',
    ], $extra);
};
$base = [$item(1), $item(2, 10, '6.00', '2.000', ['autor' => 'Pedro', 'criado_em' => '2026-11-22 19:35:00'])];
$g = AgrupadorItensComanda::agrupar($base);
$ok(count($g) === 1 && $g[0]['quantidade_texto'] === '3x' && $g[0]['total_centavos'] === 1800, 'mesmo produto soma quantidade e total em centavos');
$ok(count($g[0]['lancamentos']) === 2 && (int) $g[0]['lancamentos'][1]['id'] === 2 && $g[0]['lancamentos'][1]['autor'] === 'Pedro', 'preserva ID, autor, horario e registros individuais');
$ok(count(AgrupadorItensComanda::agrupar([$item(1), $item(2, 11)])) === 2, 'produtos de IDs diferentes nao se misturam');
$ok(count(AgrupadorItensComanda::agrupar([$item(1), $item(2, 10, '7.00')])) === 2, 'precos distintos nao se misturam');
$ok(count(AgrupadorItensComanda::agrupar([$item(1), $item(2, 10, '6.00', '1.000', ['observacao' => 'Gelada'])])) === 2, 'observacoes diferentes');
$ok(count(AgrupadorItensComanda::agrupar([$item(1), $item(2, 10, '6.00', '1.000', ['complementos_texto' => 'Limao'])])) === 2, 'complementos diferentes');
$ok(count(AgrupadorItensComanda::agrupar([$item(1), $item(2, 10, '6.00', '1.000', ['cancelado_em' => '2026-11-22 20:00:00'])])) === 2, 'cancelado separado do ativo');
$ok(count(AgrupadorItensComanda::agrupar([$item(1, 10, '6.00', '0.500', ['unidade' => 'kg']), $item(2, 10, '6.00', '0.500', ['unidade' => 'kg'])])) === 2, 'produto por peso separado');
$combo1 = [
    $item(3, 50, '30.00', '1.000', ['nome' => 'Hamburguer', 'total' => '30.00', 'combo_lote' => 'lote-a', 'combo_nome' => 'Combo Familia']),
    $item(4, 51, '10.00', '1.000', ['nome' => 'Batata', 'total' => '10.00', 'combo_lote' => 'lote-a', 'combo_nome' => 'Combo Familia']),
];
$combo2 = [$item(5, 50, '30.00', '1.000', ['nome' => 'Hamburguer', 'total' => '30.00', 'combo_lote' => 'lote-b', 'combo_nome' => 'Combo Familia'])];
$gc = AgrupadorItensComanda::agrupar([...$base, ...$combo1, ...$combo2]);
$ok(count($gc) === 3 && $gc[1]['tipo'] === 'combo' && $gc[1]['total_centavos'] === 4000 && count($gc[1]['lancamentos']) === 2, 'combo preserva componentes e total por lote');
$ok(count($gc[2]['lancamentos']) === 1 && $gc[2]['total_centavos'] === 3000, 'dois lotes diferentes continuam separados');

$comanda = [
    'pedido' => ['tipo' => 'mesa', 'numero' => 15, 'observacao' => null, 'servico_percentual' => '0.00', 'pessoas' => 1],
    'rotulo_mesa' => 'Mesa 5', 'rotulo' => 'Mesa 5', 'nome' => '', 'itens' => [$item(1), $item(2)],
    'subtotal' => 1200, 'servico' => 0, 'desconto' => 0, 'total' => 1200, 'pago' => 0, 'restante' => 1200,
];
$cupom = CupomImpressao::comanda($comanda, 'Restaurante Teste', 'conta');
$linhas = array_values(array_filter($cupom['blocos'], static fn (array $b): bool => $b['t'] === 'item'));
$ok(count($linhas) === 1 && $linhas[0]['qtd'] === '2x' && $linhas[0]['valor'] === 'R$ 12,00', 'conta impressa agrupa produtos comuns');
$novos = CupomImpressao::comanda($comanda, 'Restaurante Teste', 'itens_novos', 1);
$linhasNovas = array_values(array_filter($novos['blocos'], static fn (array $b): bool => $b['t'] === 'item'));
$ok(count($linhasNovas) === 1 && $linhasNovas[0]['qtd'] === '1x', 'impressao incremental usa apenas o novo registro');
$comanda['itens'] = $combo1;
$comboCupom = CupomImpressao::comanda($comanda, 'Restaurante Teste', 'pre_conta');
$blocosCombo = array_values(array_filter($comboCupom['blocos'], static fn (array $b): bool => $b['t'] === 'item'));
$ok(count($blocosCombo) === 1 && $blocosCombo[0]['nome'] === 'Combo Familia' && count($blocosCombo[0]['detalhes']) === 2, 'pre-conta preserva composicao do combo');
if ($falhas > 0) {
    fwrite(STDERR, "{$falhas} falha(s).\n");
    exit(1);
}
echo "OK: agrupamento, combos, historico e impressao de comanda.\n";
