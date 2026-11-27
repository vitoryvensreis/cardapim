<?php

declare(strict_types=1);

// Taxa por forma de pagamento (sem banco): leitura do que o gerente digita, calculo, textos e linha no cupom.
// php tests/unit/taxa-pagamento.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Caixa\ComandaService;
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

// Contas de mesa e balcao (caixa e garcom): forma do caixa -> regra, taxa de cada pagamento e rotulo da conta
$regras = ['credito' => $pct, 'debito' => $fixo, 'pix_entrega' => ['tipo' => 'percentual', 'valor' => '1.00'], 'pix_online' => ['tipo' => 'fixo', 'valor' => '0.99']];
$ok(TaxaPagamento::regraDaConta($regras, 'credito') === $pct && TaxaPagamento::regraDaConta($regras, 'debito') === $fixo, 'cartao do caixa usa as taxas do cartao');
$ok(TaxaPagamento::regraDaConta($regras, 'pix') === $regras['pix_entrega'], 'Pix registrado no caixa usa a taxa do Pix na entrega ou no caixa');
$ok(TaxaPagamento::regraDaConta($regras, 'pix_online') === $regras['pix_online'], 'Pix com QR do Asaas usa a taxa do Pix pelo app');
$ok(TaxaPagamento::regraDaConta($regras, 'dinheiro') === null, 'dinheiro nunca tem regra');
$ok(TaxaPagamento::daConta($regras, 'credito', 5000) === ['centavos' => 175, 'rotulo' => 'Taxa do crédito (3,5%)'], '3,5% de R$ 50,00 no credito');
$ok(TaxaPagamento::daConta($regras, 'debito', 3000) === ['centavos' => 100, 'rotulo' => 'Taxa do débito (R$ 1,00)'], 'debito com valor fixo');
$ok(TaxaPagamento::daConta($regras, 'dinheiro', 3000) === ['centavos' => 0, 'rotulo' => null], 'dinheiro sem taxa');
$ok(TaxaPagamento::daConta([], 'credito', 3000) === ['centavos' => 0, 'rotulo' => null], 'restaurante sem taxa: tudo como antes');
$ok(TaxaPagamento::rotuloDaConta([]) === null, 'conta sem taxa nao tem rotulo');
$ok(TaxaPagamento::rotuloDaConta(['Taxa do crédito (3,5%)', 'Taxa do crédito (3,5%)']) === 'Taxa do crédito (3,5%)', 'duas vezes a mesma taxa = rotulo dela');
$ok(TaxaPagamento::rotuloDaConta(['Taxa do crédito (3,5%)', 'Taxa do débito (R$ 1,00)']) === TaxaPagamento::ROTULO_VARIAS, 'taxas diferentes = Taxas de pagamento');
$tela = TaxaPagamento::paraConta($regras);
$ok(array_keys($tela) === ['debito', 'credito', 'pix', 'pix_online'], 'regras da tela pelas formas do caixa');
$ok($tela['credito']['texto'] === '+ 3,5%' && $tela['pix']['rotulo'] === 'Taxa do Pix (1%)', 'texto e rotulo na tela');
$ok(TaxaPagamento::calcularTela(5000, $tela['credito']) === TaxaPagamento::calcular(5000, $pct), 'conta da tela igual a do servidor (porcentagem)');
$ok(TaxaPagamento::calcularTela(5000, $tela['debito']) === 100 && TaxaPagamento::calcularTela(5000, null) === 0, 'conta da tela (fixo e sem regra)');
$iguais = true;
foreach ([1, 99, 4290, 4300, 12345, 99999] as $base) {
    $iguais = $iguais && TaxaPagamento::calcularTela($base, $tela['credito']) === TaxaPagamento::calcular($base, $pct);
}
$ok($iguais, 'conta da tela igual a do servidor em varios valores');

// Conferencia da taxa que a tela mostrou (caixa e garcom)
$ok(ComandaService::conferirTaxa('credito', 5000, 175, '175') === null, 'taxa vista igual: grava');
$ok(ComandaService::conferirTaxa('dinheiro', 5000, 0, '') === null && ComandaService::conferirTaxa('debito', 5000, 0, '0') === null, 'sem taxa e nada visto: grava');
$sem = ComandaService::conferirTaxa('credito', 5000, 175, '');
$ok($sem !== null && !$sem->ok && $sem->erros['taxa_vista'] === '175', 'sem JavaScript: volta com a taxa certa para confirmar');
$ok($sem !== null && $sem->mensagem === 'Crédito tem taxa de R$ 1,75. Cobre R$ 51,75 do cliente e toque em Receber de novo.', 'mensagem com a taxa e o valor a cobrar');
$mudou = ComandaService::conferirTaxa('credito', 5000, 175, '150');
$ok($mudou !== null && $mudou->erros['taxa_vista'] === '175', 'taxa mudou no meio: nao grava');
$tirada = ComandaService::conferirTaxa('credito', 5000, 0, '175');
$ok($tirada !== null && $tirada->erros['taxa_vista'] === '0', 'taxa tirada no meio: nao grava');
$ok(ComandaService::conferirTaxa('credito', 5000, 175, 'abc') !== null, 'taxa vista invalida recusada');

// Conta impressa da comanda: linha das taxas antes do TOTAL e aviso enquanto falta pagar
$comanda = [
    'pedido' => ['tipo' => 'balcao', 'numero' => 7, 'servico_percentual' => '0.00', 'pessoas' => null, 'observacao' => null],
    'itens' => [['id' => 1, 'nome' => 'Suco', 'quantidade' => '1.000', 'unidade' => 'un', 'preco_unitario' => '50.00', 'total' => '50.00', 'observacao' => null, 'cancelado_em' => null]],
    'rotulo' => 'Balcão', 'rotulo_mesa' => 'Balcão', 'nome' => '',
    'subtotal' => 5000, 'servico' => 0, 'desconto' => 0, 'total' => 5175, 'pago' => 5175, 'restante' => 0,
    'taxa_pagamento' => 175, 'taxa_pagamento_rotulo' => 'Taxa do crédito (3,5%)', 'taxas' => $tela,
];
$linhas = array_column(CupomImpressao::comanda($comanda, 'Restaurante', 'conta')['blocos'], 'rotulo');
$ok(in_array('Taxa do crédito (3,5%)', $linhas, true), 'conta impressa mostra a taxa cobrada');
$textos = array_column(CupomImpressao::comanda(['pago' => 0, 'restante' => 5000, 'total' => 5000, 'taxa_pagamento' => 0] + $comanda, 'Restaurante', 'pre_conta')['blocos'], 'texto');
$ok(in_array('Cartão e Pix podem ter taxa na hora de pagar.', $textos, true), 'pre-conta avisa que cartao e Pix podem ter taxa');
$textos = array_column(CupomImpressao::comanda(['pago' => 0, 'restante' => 5000, 'total' => 5000, 'taxa_pagamento' => 0, 'taxas' => []] + $comanda, 'Restaurante', 'pre_conta')['blocos'], 'texto');
$ok(!in_array('Cartão e Pix podem ter taxa na hora de pagar.', $textos, true), 'restaurante sem taxa: pre-conta sem o aviso');

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Taxa por forma de pagamento: tudo certo.\n";
