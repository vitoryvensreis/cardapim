<?php
/** Caixa "Em poucas palavras" no inicio de cada pagina legal. Espera: $legalResumo (lista de frases, ja em HTML seguro). */
use Cardapim\Core\Icon;
?>
<aside class="legal-resumo" aria-labelledby="legal-resumo-titulo">
    <h2 id="legal-resumo-titulo"><?= Icon::svg('info') ?>Em poucas palavras</h2>
    <ul>
        <?php foreach ($legalResumo as $item): ?>
            <li><?= Icon::svg('check', 'icon-sm') ?><span><?= $item ?></span></li>
        <?php endforeach; ?>
    </ul>
</aside>
