<?php
/**
 * Selo de disponibilidade do produto (icone + texto, nunca so a cor).
 * Espera: $p (produto do cardapio com disponivel, motivo, porcoes).
 */
use Cardapim\Core\Icon;

$porcoes = $p['porcoes'] ?? null;
if (!$p['disponivel']) {
    [$classe, $icone, $texto] = ($p['motivo'] ?? '') === 'pausado' ? ['pausado', 'pause', 'Pausado'] : ['acabou', 'circle-x', 'Acabou'];
} elseif ($porcoes !== null && $porcoes <= 5) {
    [$classe, $icone, $texto] = ['pouco', 'triangle-exclamation-point', 'Restam ' . (int) $porcoes];
} else {
    [$classe, $icone, $texto] = ['ok', 'circle-check', 'Tem'];
}
?>
<span class="produto-estado is-<?= $classe ?>"><?= Icon::svg($icone, 'icon-sm') ?><?= htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') ?></span>
