<?php
/**
 * Selo de disponibilidade do produto (icone + texto, nunca so a cor).
 * Espera: $p (produto do cardapio com disponivel, motivo, porcoes, restante, unidade).
 */
use Cardapim\Core\Icon;

$porcoes = $p['porcoes'] ?? null;
$unidadeVenda = (string) ($p['unidade'] ?? 'un');
$restante = $p['restante'] ?? ($porcoes !== null ? (int) $porcoes * 1000 : null);
if (!$p['disponivel']) {
    [$classe, $icone, $texto] = ($p['motivo'] ?? '') === 'pausado' ? ['pausado', 'pause', 'Pausado'] : ['acabou', 'circle-x', 'Acabou'];
} elseif ($restante !== null && $restante <= 5000) {
    [$classe, $icone, $texto] = ['pouco', 'triangle-exclamation-point', 'Restam ' . \Cardapim\Modules\Inventory\QuantidadeVenda::quanto((int) $restante, $unidadeVenda)];
} else {
    [$classe, $icone, $texto] = ['ok', 'circle-check', 'Tem'];
}
?>
<span class="produto-estado is-<?= $classe ?>"><?= Icon::svg($icone, 'icon-sm') ?><?= htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') ?></span>
