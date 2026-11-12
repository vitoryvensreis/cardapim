<?php
/** Paginacao das listas da plataforma. Espera: $pagina, $paginas, $link (callable array -> url). */
use Cardapim\Core\Icon;

if ($paginas <= 1) {
    return;
}
$escPag = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<nav class="gestao-paginacao" aria-label="Páginas">
    <?php if ($pagina > 1): ?>
        <a class="btn btn-outline btn-sm" href="<?= $escPag($link(['pagina' => $pagina - 1])) ?>" rel="prev"><?= Icon::svg('arrow-left', 'icon-sm') ?>Anterior</a>
    <?php endif; ?>
    <span class="texto-suave">Página <?= (int) $pagina ?> de <?= (int) $paginas ?></span>
    <?php if ($pagina < $paginas): ?>
        <a class="btn btn-outline btn-sm" href="<?= $escPag($link(['pagina' => $pagina + 1])) ?>" rel="next">Próxima<?= Icon::svg('arrow-right', 'icon-sm') ?></a>
    <?php endif; ?>
</nav>
