<?php

declare(strict_types=1);

// Opcoes do cupom (sem banco): total no delivery aceito, nome de quem atendeu e margem lateral por impressora.
// php tests/unit/opcoes-cupom.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Services\Impressao\CupomImagem;
use Cardapim\Services\Impressao\CupomImpressao;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};
$rotulos = static fn (array $blocos): array => array_values(array_filter(array_column($blocos, 'rotulo')));
$textos = static fn (array $blocos): array => array_values(array_filter(array_column($blocos, 'texto')));

$pedido = [
    'numero' => 77, 'tipo' => 'entrega', 'origem' => 'cardapio', 'cliente_nome' => 'Bia', 'observacao' => '',
    'subtotal' => '40.00', 'taxa_entrega' => '6.00', 'desconto' => '4.00', 'total' => '42.00',
    'forma_pagamento' => 'dinheiro', 'troco_para' => '50.00', 'criado_em' => '2026-11-26 20:00:00',
    'cliente_whatsapp' => '', 'entrega_logradouro' => 'Rua A', 'entrega_numero' => '10',
];
$itens = [[
    'id' => 1, 'id_produto' => 1, 'nome' => 'Pizza', 'preco_unitario' => '40.00', 'quantidade' => '1.000', 'unidade' => 'un',
    'observacao' => '', 'complementos_texto' => '', 'total' => '40.00', 'cancelado_em' => null,
]];

// Resumo do pedido: os mesmos valores do cupom completo.
$resumo = CupomImpressao::resumoPedido($pedido);
$ok($rotulos($resumo) === ['Itens', 'Entrega', 'Desconto', 'TOTAL', 'Troco para R$ 50,00'], 'resumo: itens, entrega, desconto, total e troco');
$ok(in_array('Pagamento: Dinheiro', $textos($resumo), true) || (bool) preg_grep('~^Pagamento: ~', $textos($resumo)), 'resumo: forma de pagamento');
$completo = CupomImpressao::pedido($pedido, $itens, 'Loja');
$ok(array_slice($completo['blocos'], -count($resumo)) === $resumo, 'cupom completo continua terminando no mesmo resumo');
$semTaxa = CupomImpressao::resumoPedido(['taxa_entrega' => '0.00', 'desconto' => '0.00', 'forma_pagamento' => 'pix', 'troco_para' => null] + $pedido);
$ok($rotulos($semTaxa) === ['Itens', 'TOTAL'], 'resumo sem entrega, desconto e troco quando nao ha');

// Via de producao: padrao sem o total; com resumo, o total sai no fim. Valor de cada item continua.
$cabeca = CupomImpressao::cabecaPedido($pedido);
$via = CupomImpressao::producao($cabeca, 'Cozinha', $itens, 'Carlos');
$ok(!in_array('TOTAL', $rotulos($via), true), 'via sem resumo: sem TOTAL');
$ok(array_column(array_filter($via, static fn (array $b): bool => $b['t'] === 'item'), 'valor') === ['R$ 40,00'], 'via mantem o valor do item');
$ok((bool) preg_grep('~^\d\d/\d\d/\d{4} \d\d:\d\d · Carlos$~u', $textos($via)), 'via com o nome de quem atendeu');
$comTotal = CupomImpressao::producao($cabeca, 'Cozinha', $itens, '', $resumo);
$ok(in_array('TOTAL', $rotulos($comTotal), true) && end($comTotal)['t'] === 'linha', 'via com resumo: TOTAL no fim, fechado por linha');
$ok((bool) preg_grep('~^\d\d/\d\d/\d{4} \d\d:\d\d$~', $textos($comTotal)), 'sem nome: so a data e a hora');

// Margem lateral (mm) por impressora.
$ok(ImpressoraRepository::lateral('3.5') === 3.5 && ImpressoraRepository::lateral('2,5') === 2.5 && ImpressoraRepository::lateral('0') === 0.0, 'margem aceita 0 a 8 de 0,5 em 0,5');
$ok(ImpressoraRepository::lateral('8.5') === null && ImpressoraRepository::lateral('-1') === null && ImpressoraRepository::lateral('1.3') === null
    && ImpressoraRepository::lateral('abc') === null && ImpressoraRepository::lateral(null) === null, 'margem invalida ou vazia = padrao');
$ok(CupomImagem::lado(null) === 6 && CupomImagem::lado(0.0) === 0 && CupomImagem::lado(3.5) === 28 && CupomImagem::lado(9.0) === 6, 'mm vira pontos da impressora (8 por mm)');

if (CupomImagem::disponivel()) {
    $blocos = CupomImpressao::producao($cabeca, 'Cozinha', $itens, 'Carlos', $resumo);
    $padrao = CupomImagem::png($blocos, 80, 4, false);
    $largo = CupomImagem::png($blocos, 80, 4, false, null, 5.0);
    $ok(imagesx(imagecreatefromstring($largo)) === 576, 'margem nao muda a largura do papel');
    $ok($largo !== $padrao, 'margem lateral muda o desenho');
    $ok(CupomImagem::png($blocos, 80, 4, false, null, null) === $padrao, 'sem margem escolhida = desenho de antes');
    // Com 5 mm de cada lado, nada e desenhado nos primeiros 40 pontos (a linha tracejada e o texto respeitam a margem).
    $img = imagecreatefromstring($largo);
    $vazio = true;
    for ($y = 0; $y < imagesy($img) && $vazio; $y++) {
        for ($x = 0; $x < 38; $x++) {
            if (imagecolorsforindex($img, imagecolorat($img, $x, $y))['red'] < 128) {
                $vazio = false;
                break;
            }
        }
    }
    $ok($vazio, 'nada impresso dentro da margem lateral');
}

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Opcoes do cupom: tudo certo.\n";
