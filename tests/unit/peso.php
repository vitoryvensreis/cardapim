<?php

declare(strict_types=1);

// Venda por peso, sem banco de producao: php tests/unit/peso.php
// Contas e textos (QuantidadeVenda), baixa proporcional de estoque e disponibilidade em SQLite na memoria,
// e o preco do quilo que fecha o total de um item pesado do iFood.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Services\Ifood\ImportarPedidoIfood;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};
$erro = static function (callable $f): string {
    try {
        $f();
        return '';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};

// Leitura do que a equipe digita.
$ok(QuantidadeVenda::ler('0,846', 'kg') === 846, '0,846 kg = 846 g');
$ok(QuantidadeVenda::ler('0.846', 'kg') === 846, 'ponto tambem vale');
$ok(QuantidadeVenda::ler('1,25', 'kg') === 1250, '1,25 kg');
$ok(QuantidadeVenda::ler('2', 'un') === 2000, '2 unidades');
$ok($erro(fn () => QuantidadeVenda::ler('0,5', 'un')) !== '', 'unidade nao aceita 0,5');
$ok($erro(fn () => QuantidadeVenda::ler('100', 'un')) !== '' && $erro(fn () => QuantidadeVenda::ler('0', 'un')) !== '', 'unidade de 1 a 99');
$ok($erro(fn () => QuantidadeVenda::ler('0,8463', 'kg')) !== '', 'recusa 4 casas');
$ok($erro(fn () => QuantidadeVenda::ler('0', 'kg')) !== '' && $erro(fn () => QuantidadeVenda::ler('', 'kg')) !== '', 'peso zero ou vazio recusado');
$ok($erro(fn () => QuantidadeVenda::ler('100', 'kg')) !== '', 'no maximo 99,999 kg');
$ok(str_contains($erro(fn () => QuantidadeVenda::ler('0,050', 'kg', 100)), '100 g'), 'abaixo do minimo explica o minimo');
$ok(QuantidadeVenda::ler('0,100', 'kg', 100) === 100, 'igual ao minimo passa');

// Valor: preco do quilo x peso, meio centavo para cima (igual ao ROUND do MySQL).
$ok(QuantidadeVenda::totalCentavos(5990, 846) === 5068, '0,846 kg x R$ 59,90 = R$ 50,68');
$ok(QuantidadeVenda::totalCentavos(5990, 250) === 1498, '0,250 kg x R$ 59,90 = R$ 14,975 -> R$ 14,98');
$ok(QuantidadeVenda::totalCentavos(2490, 2000) === 4980, '2 un x R$ 24,90 = R$ 49,80');
$ok(QuantidadeVenda::totalCentavos(1000, 1) === 1, '1 g de R$ 10,00 = R$ 0,01');

// Textos.
$ok(QuantidadeVenda::texto(2000, 'un') === '2x', 'un: 2x');
$ok(QuantidadeVenda::texto(846, 'kg') === '846 g', 'abaixo de 1 kg em gramas');
$ok(QuantidadeVenda::texto(1250, 'kg') === '1,250 kg', 'de 1 kg para cima em quilos');
$ok(QuantidadeVenda::texto(350, 'l') === '350 ml' && QuantidadeVenda::texto(1500, 'l') === '1,500 L', 'litro');
$ok(QuantidadeVenda::comNome(846, 'kg', 'Picanha') === '846 g Picanha', 'com nome');
$ok(QuantidadeVenda::textoDoBanco('2.000', 'un') === '2x' && QuantidadeVenda::textoDoBanco('0.846', 'kg') === '846 g', 'do banco');
$ok(QuantidadeVenda::quanto(3000, 'un') === '3' && QuantidadeVenda::quanto(846, 'kg') === '846 g', 'restam');
$ok(QuantidadeVenda::contaItens(3000, 'un') === 3 && QuantidadeVenda::contaItens(846, 'kg') === 1, 'conta itens');
$ok(QuantidadeVenda::sufixoPreco('kg') === '/kg' && QuantidadeVenda::sufixoPreco('un') === '', 'sufixo do preco');
$ok(QuantidadeVenda::unidade('xx') === 'un', 'unidade desconhecida vira un');

// iFood: preco do quilo que da exatamente o total do item.
$p = ImportarPedidoIfood::precoPorUnidade(5068, 846);
$ok($p !== null && QuantidadeVenda::totalCentavos($p, 846) === 5068, 'preco do quilo fecha o total do iFood');

// Estoque em SQLite na memoria: ficha por 1 kg vendido, baixa proporcional e disponibilidade em gramas.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY, id_restaurante INT, nome TEXT, ativo INT DEFAULT 1, disponivel INT DEFAULT 1, controla_estoque INT DEFAULT 1, unidade_venda TEXT DEFAULT 'un', venda_minima TEXT)");
$db->exec('CREATE TABLE insumos (id INTEGER PRIMARY KEY, id_restaurante INT, nome TEXT, unidade TEXT, estoque_atual TEXT, ativo INT DEFAULT 1)');
$db->exec('CREATE TABLE produto_insumos (id INTEGER PRIMARY KEY, id_restaurante INT, id_produto INT, id_insumo INT, quantidade TEXT)');
$db->exec("INSERT INTO produtos (id, id_restaurante, nome, unidade_venda, venda_minima) VALUES (1, 1, 'Picanha', 'kg', '0.100'), (2, 1, 'Lata', 'un', NULL), (3, 1, 'Sorvete', 'kg', NULL)");
$db->exec("INSERT INTO insumos (id, id_restaurante, nome, unidade, estoque_atual) VALUES (1, 1, 'Picanha crua', 'kg', '2.000'), (2, 1, 'Lata', 'un', '3.000'), (3, 1, 'Sal', 'kg', '1.000'), (4, 1, 'Massa', 'kg', '0.050')");
$db->exec("INSERT INTO produto_insumos (id_restaurante, id_produto, id_insumo, quantidade) VALUES (1, 1, 1, '1.000'), (1, 1, 3, '0.001'), (1, 2, 2, '1.000'), (1, 3, 4, '1.000')");

$baixa = new BaixaEstoque($db);
$consumo = $baixa->baixarMilesimos(1, [1 => 846]);
$ok(($consumo[1] ?? null) === '0.846', 'baixa 0,846 kg de picanha');
$ok(($consumo[3] ?? null) === '0.001', '1 g de sal por kg x 0,846 kg arredonda para 1 g');
// (SQLite faz a conta em ponto flutuante; no MySQL a coluna e DECIMAL(14,3).)
$estoque = static fn (): float => round((float) $db->query('SELECT estoque_atual FROM insumos WHERE id = 1')->fetchColumn(), 3);
$ok($estoque() === 1.154, 'estoque 2,000 - 0,846 = 1,154');
$consumo2 = $baixa->baixarMilesimos(1, [1 => 200]);
$ok(!isset($consumo2[3]), 'consumo que arredonda para zero nao baixa (0,2 g de sal)');
$baixa->devolver(1, $consumo);
$ok($estoque() === 1.8, 'devolver repoe 0,846 kg');
$ok(array_values($baixa->baixar(1, [2 => 2])) === ['2.000'], 'por unidade continua igual (2 latas)');
try {
    $baixa->baixarMilesimos(1, [1 => 1801]);
    $ok(false, 'peso maior que o estoque e recusado');
} catch (EstoqueInsuficiente) {
    $ok(true, 'peso maior que o estoque e recusado');
}

$disp = (new DisponibilidadeProdutos($db))->verificar(1);
$ok($disp[1]['restante'] === 1800 && $disp[1]['unidade'] === 'kg' && $disp[1]['disponivel'], 'restam 1,8 kg de picanha');
$ok($disp[1]['porcoes'] === 1, 'porcoes = quilos inteiros');
$ok($disp[2]['restante'] === 1000 && $disp[2]['porcoes'] === 1, 'resta 1 lata');
$ok($disp[3]['disponivel'] && $disp[3]['restante'] === 50, 'sorvete sem minimo: 50 g ainda vende');
$db->exec("UPDATE produtos SET venda_minima = '0.100' WHERE id = 3");
$disp = (new DisponibilidadeProdutos($db))->verificar(1);
$ok(!$disp[3]['disponivel'] && $disp[3]['motivo'] === DisponibilidadeProdutos::MOTIVO_SEM_INSUMO, 'menos que a venda minima = acabou');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas teste(s) da venda por peso falharam.\n");
    exit(1);
}
echo "Venda por peso: contas, textos, estoque e disponibilidade conferidos.\n";
