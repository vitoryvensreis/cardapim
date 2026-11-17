<?php

declare(strict_types=1);

// Teste sem banco da regra dos complementos: php tests/unit/complementos.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Catalog\Complementos;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};

$opcao = static fn (int $id, string $nome, string $preco, bool $vendavel = true, array $ficha = []): array => [
    'id' => $id, 'id_grupo' => 0, 'nome' => $nome, 'descricao' => null, 'preco' => $preco, 'codigo' => null, 'imagem_path' => null,
    'disponivel' => true, 'ordem' => 0, 'ficha' => $ficha, 'centavos' => (int) round((float) $preco * 100), 'vendavel' => $vendavel,
];
$grupos = [
    ['id' => 1, 'nome' => 'Borda', 'minimo' => 1, 'maximo' => 1, 'opcoes' => [$opcao(10, 'Catupiry', '8.00', true, [['id_insumo' => 5, 'quantidade' => '0.050']]), $opcao(11, 'Sem borda', '0.00')]],
    ['id' => 2, 'nome' => 'Adicionais', 'minimo' => 0, 'maximo' => 3, 'opcoes' => [$opcao(20, 'Bacon', '4.50', true, [['id_insumo' => 7, 'quantidade' => '0.030']]), $opcao(21, 'Cheddar', '3.00'), $opcao(22, 'Ovo', '2.00', false)]],
];

// Formato
$ok(Complementos::formato(null) === [] && Complementos::formato([]) === [], 'sem escolhas');
$ok(Complementos::formato([['id' => 10, 'quantidade' => 1], ['id' => 20, 'quantidade' => 2]]) === [['id' => 10, 'quantidade' => 1], ['id' => 20, 'quantidade' => 2]], 'lista de objetos');
$ok(Complementos::formato(['10', '20']) === [['id' => 10, 'quantidade' => 1], ['id' => 20, 'quantidade' => 1]], 'lista de ids do formulario');
$ok(Complementos::formato([['id' => 20], ['id' => 20]]) === [['id' => 20, 'quantidade' => 2]], 'mesma opcao somada');
$ok(Complementos::formato([['id' => 'x']]) === null && Complementos::formato('abc') === null && Complementos::formato([['id' => 1, 'quantidade' => 100]]) === null, 'formatos invalidos');

// Conferir
$r = Complementos::conferir($grupos, [['id' => 10, 'quantidade' => 1], ['id' => 20, 'quantidade' => 2], ['id' => 21, 'quantidade' => 1]]);
$ok($r['ok'] && $r['centavos'] === 800 + 900 + 300, 'preco somado por unidade');
$ok($r['texto'] === 'Borda: Catupiry · Adicionais: 2x Bacon, Cheddar', 'texto legivel');
$ok($r['insumos'] === [5 => 50, 7 => 60], 'consumo dos insumos por unidade');
$ok(count($r['itens']) === 3 && $r['itens'][1] === ['id' => 20, 'grupo' => 'Adicionais', 'nome' => 'Bacon', 'centavos' => 450, 'quantidade' => 2], 'itens copiados');
$ok(!Complementos::conferir($grupos, [])['ok'], 'obrigatorio sem escolha recusado');
$ok(str_contains((string) Complementos::conferir($grupos, [])['erro'], 'Borda'), 'mensagem cita o grupo');
$ok(!Complementos::conferir($grupos, [['id' => 10, 'quantidade' => 1], ['id' => 11, 'quantidade' => 1]])['ok'], 'acima do maximo recusado');
$ok(!Complementos::conferir($grupos, [['id' => 11, 'quantidade' => 1], ['id' => 20, 'quantidade' => 3], ['id' => 21, 'quantidade' => 1]])['ok'], 'soma acima do maximo do grupo');
$ok(!Complementos::conferir($grupos, [['id' => 11, 'quantidade' => 1], ['id' => 22, 'quantidade' => 1]])['ok'], 'opcao que acabou recusada');
$ok(!Complementos::conferir($grupos, [['id' => 11, 'quantidade' => 1], ['id' => 999, 'quantidade' => 1]])['ok'], 'opcao de fora recusada');
$ok(!Complementos::conferir([], [['id' => 10, 'quantidade' => 1]])['ok'], 'produto sem grupos nao aceita complemento');
$ok(Complementos::conferir([], [])['ok'] && Complementos::conferir([], [])['centavos'] === 0, 'produto sem grupos continua igual');
$s = Complementos::conferir($grupos, [['id' => 11, 'quantidade' => 1]]);
$ok($s['ok'] && $s['centavos'] === 0 && $s['texto'] === 'Borda: Sem borda', 'opcao gratis');

// Bloqueio do produto
$ok(!Complementos::bloqueia($grupos), 'grupos com opcoes vendaveis');
$semBorda = [['id' => 1, 'nome' => 'Borda', 'minimo' => 1, 'maximo' => 1, 'opcoes' => [$opcao(10, 'Catupiry', '8.00', false)]]];
$ok(Complementos::bloqueia($semBorda), 'obrigatorio sem opcao bloqueia o produto');
$ok(Complementos::consumoDaLinha([5 => 50, 7 => 60], 3) === [5 => 150, 7 => 180], 'consumo multiplicado pelas unidades');

// Publico: sem ficha tecnica, codigo ou estoque
$pub = Complementos::publico($grupos);
$ok(!isset($pub[0]['opcoes'][0]['ficha']) && !isset($pub[0]['opcoes'][0]['codigo']) && $pub[1]['opcoes'][2]['disponivel'] === false, 'dados publicos sem segredo');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) nos complementos.\n");
    exit(1);
}
echo "OK: complementos (formato, conferencia, preco, consumo e bloqueio).\n";
