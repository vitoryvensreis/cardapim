<?php

declare(strict_types=1);

// Taxa por forma de pagamento (sem banco): leitura do que o gerente digita, calculo, textos e linha no cupom.
// php tests/unit/taxa-pagamento.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Impressao\CupomImpressao;
use Cardapim\Services\Pedidos\TaxaPagamento;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

// Leitura
$ok(TaxaPagamento::ler('', '5') === ['regra' => null, 'erro' => null], 'sem tipo = sem taxa (valor ignorado)');
$ok(TaxaPagamento::ler('percentual', '3,5')['regra'] === ['tipo' => 'percentual', 'valor' => '3.50'], 'porcentagem com virgula');
$ok(TaxaPagamento::ler('percentual', '3.5 %')['regra'] === ['tipo' => 'percentual', 'valor' => '3.50'], 'porcentagem com ponto e sinal de %');
$ok(TaxaPagamento::ler('percentual', '20')['regra'] === ['tipo' => 'percentual', 'valor' => '20.00'], 'porcentagem no limite (20%)');
$ok(TaxaPagamento::ler('percentual', '20,01')['erro'] !== null, 'porcentagem acima de 20% recusada');
$ok(TaxaPagamento::ler('percentual', '0')['erro'] !== null && TaxaPagamento::ler('percentual', '')['erro'] !== null, 'porcentagem zero ou vazia recusada');
$ok(TaxaPagamento::ler('percentual', 'abc')['erro'] !== null && TaxaPagamento::ler('percentual', '3,555')['erro'] !== null, 'porcentagem invalida recusada');
$ok(TaxaPagamento::ler('fixo', '1,00')['regra'] === ['tipo' => 'fixo', 'valor' => '1.00'], 'valor fixo em reais');
$ok(TaxaPagamento::ler('fixo', 'R$ 2,5')['regra'] === ['tipo' => 'fixo', 'valor' => '2.50'], 'valor fixo com R$');
$ok(TaxaPagamento::ler('fixo', '50,01')['erro'] !== null && TaxaPagamento::ler('fixo', '0,00')['erro'] !== null, 'valor fixo fora de R$ 0,01 a R$ 50,00 recusado');
$ok(TaxaPagamento::ler('outro', '1')['erro'] !== null, 'tipo desconhecido recusado');

// Calculo
$pct = ['tipo' => 'percentual', 'valor' => '3.50'];
$fixo = ['tipo' => 'fixo', 'valor' => '1.00'];
$ok(TaxaPagamento::calcular(10000, $pct) === 350, '3,5% de R$ 100,00 = R$ 3,50');
$ok(TaxaPagamento::calcular(4290, $pct) === 150, '3,5% de R$ 42,90 = R$ 1,50 (1,5015 arredonda para 1,50)');
$ok(TaxaPagamento::calcular(4300, $pct) === 151, '3,5% de R$ 43,00 = R$ 1,51 (1,505 arredonda meio para cima)');
$ok(TaxaPagamento::calcular(1, ['tipo' => 'percentual', 'valor' => '0.01']) === 0, 'porcentagem minuscula pode dar zero');
$ok(TaxaPagamento::calcular(5000, $fixo) === 100, 'valor fixo nao depende do total');
$ok(TaxaPagamento::calcular(5000, null) === 0, 'sem regra = sem taxa');
$ok(TaxaPagamento::calcular(0, $fixo) === 0, 'pedido zerado nao cobra taxa');

// Textos
$ok(TaxaPagamento::texto($pct) === '+ 3,5%' && TaxaPagamento::texto($fixo) === '+ R$ 1,00', 'texto ao lado da forma');
$ok(TaxaPagamento::rotulo('credito', $pct) === 'Taxa do crédito (3,5%)', 'rotulo do credito');
$ok(TaxaPagamento::rotulo('debito', $fixo) === 'Taxa do débito (R$ 1,00)', 'rotulo do debito');
$ok(TaxaPagamento::rotulo('pix_entrega', ['tipo' => 'percentual', 'valor' => '2.00']) === 'Taxa do Pix (2%)', 'rotulo do Pix');
$ok(mb_strlen(TaxaPagamento::rotulo('credito', ['tipo' => 'fixo', 'valor' => '50.00'])) <= 60, 'rotulo cabe na coluna (60)');
$ok(TaxaPagamento::paraCheckout($pct) === ['tipo' => 'percentual', 'valor' => 350, 'texto' => '+ 3,5%'], 'regra para o checkout em centesimos');

// Cupom impresso: linha da taxa antes do TOTAL (so quando ha taxa)
$pedido = ['subtotal' => '40.00', 'taxa_entrega' => '5.00', 'desconto' => '0.00', 'taxa_pagamento' => '1.58', 'taxa_pagamento_rotulo' => 'Taxa do crédito (3,5%)',
    'total' => '46.58', 'forma_pagamento' => 'credito', 'tipo' => 'entrega', 'troco_para' => null];
$rotulos = array_values(array_filter(array_column(CupomImpressao::resumoPedido($pedido), 'rotulo')));
$ok($rotulos === ['Itens', 'Entrega', 'Taxa do crédito (3,5%)', 'TOTAL'], 'cupom com a taxa antes do total');
$semTaxa = array_values(array_filter(array_column(CupomImpressao::resumoPedido(['taxa_pagamento' => '0.00'] + $pedido), 'rotulo')));
$ok(!in_array('Taxa do crédito (3,5%)', $semTaxa, true), 'cupom sem taxa nao mostra a linha');
$antigo = $pedido;
unset($antigo['taxa_pagamento'], $antigo['taxa_pagamento_rotulo']);
$ok(count(CupomImpressao::resumoPedido($antigo)) === 4, 'pedido de antes da migracao continua igual');

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Taxa por forma de pagamento: tudo certo.\n";
