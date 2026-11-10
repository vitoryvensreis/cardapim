<?php
/** Abas da secao Estoque. Espera: $base, $abaAtual, $contagem. */
use Cardapim\Core\Icon;

$abas = [
    'resumo' => ['Resumo', 'chart-no-axes-column', '/estoque', null],
    'produtos' => ['Produtos', 'hamburger', '/estoque/produtos', $contagem['produtos'] ?? null],
    'insumos' => ['Insumos', 'wheat', '/estoque/insumos', $contagem['insumos'] ?? null],
    'categorias' => ['Categorias', 'tags', '/estoque/categorias', $contagem['categorias'] ?? null],
];
?>
<nav class="painel-abas" aria-label="Partes do estoque">
    <?php foreach ($abas as $chave => [$rotulo, $icone, $caminho, $numero]): ?>
        <a href="<?= htmlspecialchars($base . $caminho, ENT_QUOTES, 'UTF-8') ?>"<?= $abaAtual === $chave ? ' aria-current="page"' : '' ?>>
            <?= Icon::svg($icone, 'icon-sm') ?><?= $rotulo ?>
            <?php if ($numero !== null): ?><span class="contador"><?= (int) $numero ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
