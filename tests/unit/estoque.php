<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/estoque.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Modules\Inventory\UnidadeMedida;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};
$erro = static function (callable $f): bool {
    try {
        $f();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};

$ok(Quantidade::paraMilesimos('0.080') === 80, '0.080 kg = 80');
$ok(Quantidade::paraMilesimos('0,08') === 80, 'virgula decimal');
$ok(Quantidade::paraMilesimos('1') === 1000 && Quantidade::paraMilesimos(2) === 2000, 'inteiros');
$ok(Quantidade::paraMilesimos('12.5') === 12500, '12,5');
$ok(Quantidade::paraMilesimos(0.1) === 100, 'float de codigo');
$ok(Quantidade::paraMilesimos('-0.5') === -500, 'negativo');
$ok($erro(fn () => Quantidade::paraMilesimos('0.0001')), 'recusa 4 casas');
$ok($erro(fn () => Quantidade::paraMilesimos('abc')) && $erro(fn () => Quantidade::paraMilesimos('')), 'recusa texto');
$ok(Quantidade::paraDecimal(80) === '0.080' && Quantidade::paraDecimal(1000) === '1.000' && Quantidade::paraDecimal(-240) === '-0.240', 'para decimal');
// 0.1 + 0.2 com float daria 0.30000000000000004; em milesimos e exato.
$ok(Quantidade::paraDecimal(Quantidade::paraMilesimos('0.1') + Quantidade::paraMilesimos('0.2')) === '0.300', 'soma exata');
$ok(Quantidade::paraDecimal(Quantidade::paraMilesimos('1.000') - 3 * Quantidade::paraMilesimos('0.080')) === '0.760', '1 kg - 3 x 80 g');
$ok(Quantidade::quantasVezes(1000, 80) === 12, '1 kg rende 12 burgers de 80 g');
$ok(Quantidade::quantasVezes(79, 80) === 0 && Quantidade::quantasVezes(0, 80) === 0, 'nao rende');
$ok($erro(fn () => Quantidade::quantasVezes(10, 0)), 'necessario zero');

$ok(UnidadeMedida::from('kg')->formatar(80) === '0,08 kg', 'formata kg');
$ok(UnidadeMedida::from('un')->formatar(3000) === '3 un', 'formata un');
$ok(UnidadeMedida::from('l')->formatar(500) === '0,5 L', 'formata litro');
$ok(UnidadeMedida::tryFrom('cx') === null, 'unidade invalida');
$ok(count(UnidadeMedida::cases()) === 3, 'tres unidades');

if ($falhas > 0) {
    exit(1);
}
echo "Estoque: quantidades exatas e unidades validadas.\n";
