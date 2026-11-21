<?php

declare(strict_types=1);

// Teste sem banco da regra dos combos (limites e divisao do preco): php tests/unit/combos.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Catalog\Combo;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};
$op = static fn (int $id, string $nome, int $extra, ?int $produto, string $produtoNome, int $produtoCentavos, ?int $max = null, array $ficha = [], ?string $codigo = null): array => [
    'id' => $id, 'nome' => $nome, 'preco' => sprintf('%d.%02d', intdiv($extra, 100), $extra % 100), 'centavos' => $extra, 'vendavel' => true, 'disponivel' => true,
    'id_produto' => $produto, 'produto_nome' => $produtoNome, 'produto_centavos' => $produtoCentavos, 'maximo' => $max, 'ficha' => $ficha, 'codigo' => $codigo, 'descricao' => null, 'imagem_path' => null,
];
// Combo Familia: 1 baiao (30,00) + 1 batata (20,00) inclusos, escolhe 2 espetos (25,00 cada; picanha 30,00 e +5,00, no maximo 1).
$combo = [
    'maximo' => 4,
    'fixos' => [
        ['id_produto' => 1, 'nome' => 'Baião', 'centavos' => 3000, 'quantidade' => 1, 'disponivel' => true],
        ['id_produto' => 2, 'nome' => 'Batata', 'centavos' => 2000, 'quantidade' => 1, 'disponivel' => true],
    ],
    'grupos' => [
        ['id' => 9, 'nome' => 'Espetos', 'minimo' => 2, 'maximo' => 3, 'opcoes' => [
            $op(11, 'Carne', 0, 10, 'Espeto de carne', 2500),
            $op(12, 'Frango', 0, 11, 'Espeto de frango', 2500, null, [], 'FRANGO-1'),
            $op(13, 'Picanha', 500, 12, 'Espeto de picanha', 3000, 1),
        ]],
        ['id' => 8, 'nome' => 'Molho', 'minimo' => 0, 'maximo' => 1, 'opcoes' => [
            $op(14, 'Molho da casa', 200, null, '', 0, null, [['id_insumo' => 7, 'quantidade' => '0.050']]),
        ]],
    ],
    'disponivel' => true, 'faltando' => [],
];
$soma = static fn (array $m): int => array_sum(array_map(static fn (array $l): int => $l['centavos'] * $l['unidades'], $m['linhas']));

// Limites.
$c = Combo::conferir($combo, [['id' => 11, 'quantidade' => 1], ['id' => 12, 'quantidade' => 1]]);
$ok($c['ok'] && $c['extras'] === 0, '1 carne + 1 frango: ok');
$ok(!Combo::conferir($combo, [['id' => 11, 'quantidade' => 1]])['ok'], 'menos que o minimo do grupo');
$ok(str_contains((string) Combo::conferir($combo, [['id' => 13, 'quantidade' => 2]])['erro'], 'No máximo 1 de "Picanha"'), 'maximo por opcao');
$ok(str_contains((string) Combo::conferir($combo, [['id' => 11, 'quantidade' => 3]])['erro'], 'até 4 itens (2 já inclusos)'), 'limite total do combo');
$semMax = array_replace($combo, ['maximo' => null]);
$ok(Combo::conferir($semMax, [['id' => 11, 'quantidade' => 3]])['ok'], 'sem limite total: vale o maximo do grupo');
$fora = $combo;
$fora['fixos'][1]['disponivel'] = false;
$ok(str_contains((string) Combo::conferir($fora, [['id' => 11, 'quantidade' => 2]])['erro'], '"Batata" acabou'), 'item incluso sem estoque bloqueia');

// Divisao: 100,00 cheio vendido a 95,00 fecha ao centavo, proporcional ao preco de cada item.
$m = Combo::montar($combo, $c['escolhidos'], 9500, 1);
$ok($soma($m) === 9500 && $m['total'] === 9500, 'combo a 95,00 fecha 95,00');
$valor = static fn (array $m, string $nome): int => (int) (array_values(array_filter($m['linhas'], static fn (array $l): bool => $l['nome'] === $nome))[0]['centavos'] ?? -1);
$ok($valor($m, 'Baião') === 2850 && $valor($m, 'Batata') === 1900 && $valor($m, 'Espeto de carne') === 2375, 'desconto de 5% em cada item');
$ok($m['produtos'] === [1 => 1000, 2 => 1000, 10 => 1000, 11 => 1000], 'estoque de cada produto');
$mesmo = Combo::montar($combo, Combo::conferir($combo, [['id' => 11, 'quantidade' => 2]])['escolhidos'], 10000, 1);
$ok($soma($mesmo) === 10000 && $valor($mesmo, 'Espeto de carne') === 2500, 'mesmo valor da soma: sem desconto');
$carne = array_values(array_filter($mesmo['linhas'], static fn (array $l): bool => $l['nome'] === 'Espeto de carne'));
$ok(count($carne) === 1 && $carne[0]['unidades'] === 2, '2 espetos de carne numa linha so');

// Picanha +5,00 e molho sem produto (+2,00, baixa a ficha da opcao); 2 combos.
$cp = Combo::conferir($semMax, [['id' => 11, 'quantidade' => 1], ['id' => 13, 'quantidade' => 1], ['id' => 14, 'quantidade' => 1]]);
$mp = Combo::montar($semMax, $cp['escolhidos'], 9500, 2);
$ok($cp['extras'] === 700 && $soma($mp) === (9500 + 700) * 2, 'adicionais somam no total (2 combos)');
$ok($valor($mp, 'Molho da casa') === 200 && $mp['insumos'] === [7 => 100], 'opcao sem produto vira linha e baixa a ficha');
$ok(($mp['produtos'][12] ?? 0) === 2000, 'picanha baixa o estoque do produto');

// iFood: so desmembra quando reconhece todas as escolhas; o valor e o que o iFood cobrou.
$ei = Combo::escolhasIfood($combo, [['codigo' => 'DZ-C11', 'quantidade' => 1], ['codigo' => 'frango-1', 'quantidade' => 1]]);
$ok($ei !== null && count($ei) === 2, 'reconhece DZ-C e o codigo da opcao');
$ok(Combo::escolhasIfood($combo, [['codigo' => 'XYZ', 'quantidade' => 1]]) === null, 'codigo desconhecido: entra como antes');
$ok(Combo::escolhasIfood($combo, [['codigo' => 'DZ-C11', 'quantidade' => null]]) === null, 'quantidade quebrada: entra como antes');
$mi = Combo::montar($combo, $ei, 0, 1, 8990);
$ok($soma($mi) === 8990, 'valor do iFood dividido ao centavo');

$pub = Combo::publico($combo);
$ok($pub['inclusos'] === ['1x Baião', '1x Batata'] && $pub['maximo_escolhas'] === 2 && $pub['grupos'][0]['opcoes'][2]['maximo'] === 1, 'dados do navegador');
$ok(!str_contains((string) json_encode($pub), 'ficha'), 'navegador nao recebe a ficha');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: combos (limites, divisao do preco, adicionais, iFood).\n";
