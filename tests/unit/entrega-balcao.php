<?php

declare(strict_types=1);

// Entrega feita no caixa (sem banco): telefone, totais com taxa (sem servico), troco, selo "Feito no caixa".
// php tests/unit/entrega-balcao.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Caixa\EntregaBalcaoService as E;
use Cardapim\Services\Impressao\CupomImpressao;
use Cardapim\Services\Pedidos\StatusPedido;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

// Telefone com DDD.
$ok(E::telefone('(85) 99999-1234') === '85999991234', 'telefone celular com mascara');
$ok(E::telefone('+55 85 3333-4444') === '8533334444', 'telefone fixo com +55');
$ok(E::telefone('5585999991234') === '85999991234', 'tira o 55 do pais');
$ok(E::telefone('99999-1234') === null && E::telefone('') === null && E::telefone('0859999912345') === null, 'sem DDD ou invalido recusado');

// Totais: itens - desconto + taxa; o servico nao entra na entrega.
$ok(E::totais(3000, 0, 500) === ['subtotal' => 3000, 'desconto' => 0, 'taxa' => 500, 'total' => 3500], 'itens + taxa');
$ok(E::totais(3000, 1000, 500)['total'] === 2500, 'desconto da venda continua valendo');
$ok(E::totais(1000, 5000, 0)['desconto'] === 1000 && E::totais(1000, 5000, 0)['total'] === 0, 'desconto nunca passa do total');
$ok(E::totais(2000, 0, 0)['total'] === 2000, 'entrega gratis');

// Troco: vazio = sem troco; menor que o total recusado; troco alto demais recusado.
$t = E::troco('', 3500);
$ok($t['ok'] && $t['centavos'] === null && $t['troco'] === 0, 'sem valor = sem troco');
$t = E::troco('50,00', 3500);
$ok($t['ok'] && $t['troco'] === 1500, 'cliente entregou 50 numa conta de 35: troco 15');
$t = E::troco('35,00', 3500);
$ok($t['ok'] && $t['troco'] === 0, 'valor exato: troco zero');
$ok(!E::troco('20,00', 3500)['ok'], 'valor menor que o total recusado');
$ok(!E::troco('abc', 3500)['ok'], 'valor invalido recusado');
$ok(!E::troco('5000,00', 3500)['ok'], 'troco acima de R$ 1.000 recusado');

// Formas: as mesmas do caixa.
$ok(array_keys(E::FORMAS) === ['dinheiro', 'pix', 'credito', 'debito'], 'formas: dinheiro, pix, credito e debito');

// "Feito no caixa": so pedido de entrega/retirada que veio do balcao.
$pedido = ['numero' => 12, 'tipo' => 'entrega', 'origem' => 'balcao', 'cliente_nome' => 'Ana', 'observacao' => ''];
$ok(StatusPedido::doCaixa($pedido), 'entrega vinda do balcao e do caixa');
$ok(!StatusPedido::doCaixa(['tipo' => 'balcao', 'origem' => 'balcao']), 'venda normal do balcao nao e entrega');
$ok(!StatusPedido::doCaixa(['tipo' => 'entrega', 'origem' => 'cardapio']), 'pedido do cardapio online nao e do caixa');
$ok(str_contains(CupomImpressao::cabecaPedido($pedido)['linha'], 'FEITO NO CAIXA'), 'comanda da cozinha mostra FEITO NO CAIXA');
$ok(!str_contains(CupomImpressao::cabecaPedido(['origem' => 'cardapio'] + $pedido)['linha'], 'CAIXA'), 'pedido do cardapio sem o selo');

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Entrega no balcao: tudo certo.\n";
