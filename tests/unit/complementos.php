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

// Quantidade por opcao (ex.: 3 ovos): so no grupo que repete, com limite por opcao.
$ovos = [['id' => 5, 'nome' => 'Adicionais', 'minimo' => 0, 'maximo' => 10, 'repetir' => false, 'opcoes' => [
    ['id' => 50, 'nome' => 'Ovo', 'preco' => '1.00', 'centavos' => 100, 'vendavel' => true, 'ficha' => [], 'qtd_max' => null, 'imagem_path' => null],
    ['id' => 51, 'nome' => 'Bacon', 'preco' => '3.00', 'centavos' => 300, 'vendavel' => true, 'ficha' => [], 'qtd_max' => 2, 'imagem_path' => null],
]]];
$ok(!Complementos::conferir($ovos, [['id' => 50, 'quantidade' => 3]])['ok'], 'grupo sem quantidade: opcao uma vez so');
$ovos[0]['repetir'] = true;
$r3 = Complementos::conferir($ovos, [['id' => 50, 'quantidade' => 3]]);
$ok($r3['ok'] && $r3['centavos'] === 300 && str_contains($r3['texto'], '3x Ovo'), 'grupo com quantidade: 3 ovos somam e aparecem no texto');
$ok(!Complementos::conferir($ovos, [['id' => 51, 'quantidade' => 3]])['ok'], 'limite da opcao respeitado (bacon ate 2)');
$ok(!Complementos::conferir($ovos, [['id' => 50, 'quantidade' => 9], ['id' => 51, 'quantidade' => 2]])['ok'], 'soma continua limitada pelo maximo do grupo');
$pubOvos = Complementos::publico($ovos);
$ok($pubOvos[0]['repetir'] === true && $pubOvos[0]['opcoes'][1]['maximo'] === 2 && $pubOvos[0]['opcoes'][0]['maximo'] === null, 'dados publicos com quantidade e limite');
$semChave = [['id' => 6, 'nome' => 'Antigo', 'minimo' => 0, 'maximo' => 5, 'opcoes' => [['id' => 60, 'nome' => 'X', 'preco' => '0.00', 'centavos' => 0, 'vendavel' => true, 'ficha' => []]]]];
$ok(Complementos::conferir($semChave, [['id' => 60, 'quantidade' => 2]])['ok'], 'grupo sem a informacao (combo antigo) continua aceitando quantidade');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) nos complementos.\n");
    exit(1);
}
echo "OK: complementos (formato, conferencia, preco, consumo e bloqueio).\n";
