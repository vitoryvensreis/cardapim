<?php

declare(strict_types=1);

// Meio a Meio (sem banco): fracoes, preco pelas regras, conferencia das escolhas (grupo "Sabores"),
// estoque por fracao, reconhecimento dos sabores do iFood e "A partir de".
// php tests/unit/meio-a-meio.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Modules\Catalog\MeioAMeio as M;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

// Fracoes e textos.
$ok(M::fracoes(1) === [1000] && M::fracoes(2) === [500, 500] && M::fracoes(3) === [333, 333, 334] && M::fracoes(4) === [250, 250, 250, 250], 'fracoes somam 1000 (0,5 / 0,333 / 0,25)');
$ok(M::fracaoTexto(1) === '' && M::fracaoTexto(2) === '1/2' && M::fracaoTexto(4) === '1/4', 'texto da fracao');

// Preco pelas regras.
$ok(M::preco('fracionado', 0, [4000, 5000]) === 4500, 'fracionado: media de 40 e 50 = 45');
$ok(M::preco('fracionado', 0, [1000, 1000, 1001]) === 1000, 'fracionado: media arredondada ao centavo');
$ok(M::preco('fracionado', 0, [999, 1000]) === 1000, 'fracionado: meio centavo arredonda para cima');
$ok(M::preco('maior', 0, [4000, 5000, 3000]) === 5000, 'maior: sabor mais caro');
$ok(M::preco('personalizado', 3990, [4000, 5000]) === 3990, 'personalizado: preco do produto');
$ok(M::preco('fracionado', 3990, []) === 3990, 'sem sabores: preco do produto');

// Grupo "Sabores" como sai de MeioAMeio::carregar().
$sabor = static fn (int $id, string $nome, int $c, bool $vende = true): array => ['id' => M::OFFSET + $id, 'id_produto' => $id, 'nome' => $nome, 'descricao' => '',
    'preco' => '0.00', 'centavos' => 0, 'sabor_centavos' => $c, 'codigo' => 'PZ-' . $id, 'imagem_path' => null, 'ficha' => [], 'disponivel' => true, 'vendavel' => $vende];
$grupo = static fn (string $regra, int $min, int $max): array => ['id' => M::OFFSET, 'nome' => 'Sabores', 'minimo' => $min, 'maximo' => $max, 'repetir' => false,
    'meio' => ['regra' => $regra, 'base' => 3000], 'opcoes' => [$sabor(11, 'Calabresa', 4000), $sabor(12, 'Mista', 5000), $sabor(13, 'Frango', 4400), $sabor(14, 'Atum', 6000, false)]];
$esc = static fn (int ...$ids): array => array_map(static fn (int $i): array => ['id' => M::OFFSET + $i, 'quantidade' => 1], $ids);

$c = Complementos::conferir([$grupo('fracionado', 1, 2)], $esc(11, 12));
$ok($c['ok'] && 3000 + $c['centavos'] === 4500, 'fracionado 2 sabores: item sai por R$ 45 (base + diferenca)');
$ok($c['produtos'] === [11 => 500, 12 => 500], 'estoque: 0,5 de cada sabor');
$ok($c['texto'] === 'Sabores: 1/2 Calabresa, 1/2 Mista', 'texto do item com as fracoes');
$ok($c['itens'] === [], 'sabor nao vira complemento gravado');
$c = Complementos::conferir([$grupo('maior', 1, 3)], $esc(11, 12, 13));
$ok($c['ok'] && 3000 + $c['centavos'] === 5000 && $c['produtos'] === [11 => 333, 12 => 333, 13 => 334], 'maior com 3 sabores: R$ 50 e 0,333 de cada');
$c = Complementos::conferir([$grupo('personalizado', 1, 2)], $esc(11, 12));
$ok($c['ok'] && $c['centavos'] === 0, 'personalizado: vale o preco do produto');
$c = Complementos::conferir([$grupo('fracionado', 1, 2)], $esc(11));
$ok($c['ok'] && 3000 + $c['centavos'] === 4000 && $c['produtos'] === [11 => 1000] && $c['texto'] === 'Sabores: Calabresa', 'um sabor so: inteiro');

$ok(!Complementos::conferir([$grupo('fracionado', 2, 2)], $esc(11))['ok'], 'minimo 2 e maximo 2: com 1 sabor recusado');
$ok(Complementos::conferir([$grupo('fracionado', 2, 2)], $esc(11, 12))['ok'], 'minimo 2 e maximo 2: com 2 sabores aceito');
$ok(!Complementos::conferir([$grupo('fracionado', 1, 2)], $esc(11, 12, 13))['ok'], 'passar do maximo recusado');
$ok(!Complementos::conferir([$grupo('fracionado', 1, 2)], [['id' => M::OFFSET + 11, 'quantidade' => 2]])['ok'], 'o mesmo sabor duas vezes recusado');
$r = Complementos::conferir([$grupo('fracionado', 1, 2)], $esc(14));
$ok(!$r['ok'] && str_contains((string) $r['erro'], 'Atum'), 'sabor sem estoque recusado');
$ok(!Complementos::conferir([$grupo('fracionado', 1, 2)], [['id' => M::OFFSET + 99, 'quantidade' => 1]])['ok'], 'sabor de fora da lista recusado');
$ok(Complementos::bloqueia([['minimo' => 2, 'opcoes' => [$sabor(1, 'A', 1), $sabor(2, 'B', 1, false)]]]), 'menos sabores a venda que o minimo: produto indisponivel');

// Meio a Meio junto com um grupo comum (borda): a borda soma normalmente.
$borda = ['id' => 7, 'nome' => 'Borda', 'minimo' => 0, 'maximo' => 1, 'repetir' => false,
    'opcoes' => [['id' => 70, 'nome' => 'Catupiry', 'preco' => '8.00', 'centavos' => 800, 'ficha' => [], 'vendavel' => true, 'imagem_path' => null]]];
$c = Complementos::conferir([$grupo('fracionado', 1, 2), $borda], [...$esc(11, 12), ['id' => 70, 'quantidade' => 1]]);
$ok($c['ok'] && 3000 + $c['centavos'] === 5300 && count($c['itens']) === 1, 'borda soma por cima do Meio a Meio (45 + 8)');

// Dados para o navegador.
$pub = Complementos::publico([$grupo('fracionado', 1, 2)]);
$ok($pub[0]['meio'] === ['regra' => 'fracionado', 'base' => 3000] && $pub[0]['opcoes'][0]['sabor_centavos'] === 4000 && $pub[0]['opcoes'][0]['preco'] === 'R$ 40,00', 'publico: regra e preco de cada sabor');
$ok(!isset($pub[0]['opcoes'][0]['codigo']), 'publico: sem codigo interno');
$ok(Complementos::publico([$borda])[0]['meio'] === null, 'grupo comum sem meio');
$ok(Complementos::formato([['id' => M::OFFSET + 11, 'quantidade' => 1]]) === [['id' => M::OFFSET + 11, 'quantidade' => 1]], 'id do sabor passa no formato');
$ok(M::ehSabor(M::OFFSET + 1) && !M::ehSabor(70), 'id de sabor reconhecido');

// "A partir de".
$ok(M::aPartirDe($grupo('fracionado', 2, 2)) === 4200, 'a partir de (fracionado, 2 sabores): media dos 2 mais baratos');
$ok(M::aPartirDe($grupo('maior', 2, 2)) === 4400, 'a partir de (maior, 2 sabores): o segundo mais barato');
$ok(M::aPartirDe($grupo('fracionado', 1, 2)) === 4000, 'a partir de com 1 sabor: o mais barato a venda');
$ok(M::aPartirDe($grupo('personalizado', 1, 2)) === null, 'personalizado: preco fixo, sem "a partir de"');

// iFood: sabores pelo codigo, por DZ-P<id> ou pelo nome.
$g = $grupo('fracionado', 1, 2);
$ok(M::saboresIfood($g, [['codigo' => 'PZ-11', 'nome' => 'x', 'quantidade' => 1], ['codigo' => 'dz-p12', 'nome' => 'y', 'quantidade' => 1]]) === [11 => 500, 12 => 500], 'iFood: codigo do produto e DZ-P');
$ok(M::saboresIfood($g, [['codigo' => null, 'nome' => '1/2 Calabresa', 'quantidade' => 1], ['codigo' => null, 'nome' => 'mista', 'quantidade' => 1]]) === [11 => 500, 12 => 500], 'iFood: pelo nome (com ou sem 1/2)');
$ok(M::saboresIfood($g, [['codigo' => null, 'nome' => 'Portuguesa', 'quantidade' => 1]]) === null, 'iFood: sabor desconhecido = entra como antes');
$ok(M::saboresIfood($g, []) === null, 'iFood: sem opcoes = entra como antes');

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Meio a Meio: tudo certo.\n";
