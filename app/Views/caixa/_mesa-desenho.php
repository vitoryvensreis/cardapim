<?php
/**
 * Desenho da mesa vista de cima: tampo no meio e cadeiras em volta (quantas = lugares, de 2 a 8 no desenho).
 * Espera: $lugares (int|null), $centro (HTML ja escapado do tampo).
 */
$n = max(2, min(8, (int) ($lugares ?: 4)));
// Distribuicao: 1 cadeira em cada lado (a partir de 3/4 lugares) e o resto em cima e embaixo.
$lados = $n >= 4 ? 2 : ($n === 3 ? 1 : 0);
$cima = (int) ceil(($n - $lados) / 2);
$baixo = $n - $lados - $cima;
$cadeiras = static fn (int $q, string $classe): string => str_repeat('<span class="cadeira ' . $classe . '"></span>', $q);
?>
<span class="mesa-desenho mesa-desenho-<?= $cima ?>" aria-hidden="true">
    <span class="cadeiras"><?= $cadeiras($cima, 'cadeira-cima') ?></span>
    <span class="mesa-meio">
        <?= $lados >= 1 ? $cadeiras(1, 'cadeira-lado') : '' ?>
        <span class="mesa-tampo"><?= $centro ?></span>
        <?= $lados >= 2 ? $cadeiras(1, 'cadeira-lado') : '' ?>
    </span>
    <span class="cadeiras"><?= $cadeiras($baixo, 'cadeira-baixo') ?></span>
</span>
