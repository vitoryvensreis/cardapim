<?php
/**
 * Consulta do cardapio para o garcom: preco, se tem e quantas unidades restam (quando controla estoque).
 * Espera: $cardapio (categorias com produtos, disponivel, motivo, porcoes).
 */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$total = 0;
$falta = 0;
foreach ($cardapio as $cat) {
    foreach ($cat['produtos'] as $p) {
        $total++;
        $falta += $p['disponivel'] ? 0 : 1;
    }
}
?>
<div class="garcom-pagina garcom-cardapio" data-consulta>
    <header class="garcom-cabecalho">
        <h1>Cardápio</h1>
        <label class="busca-campo busca-grande">
            <?= Icon::svg('search', 'icon-sm') ?>
            <span class="sr-only">Buscar produto</span>
            <input type="search" placeholder="Buscar produto ou preço" autocomplete="off" data-busca-produto>
        </label>
        <?php if ($total > 0): ?>
            <nav class="garcom-filtros" aria-label="Filtrar produtos" data-produtos-filtro>
                <button type="button" aria-pressed="true" data-filtro-produto="todos"><span>Todos</span><b><?= $total ?></b></button>
                <button type="button" aria-pressed="false" data-filtro-produto="tem" class="is-livre"><?= Icon::svg('circle-check', 'icon-sm') ?><span>Tem</span><b><?= $total - $falta ?></b></button>
                <button type="button" aria-pressed="false" data-filtro-produto="falta" class="is-falta"><?= Icon::svg('circle-x', 'icon-sm') ?><span>Em falta</span><b><?= $falta ?></b></button>
            </nav>
        <?php endif; ?>
    </header>

    <?php if ($total === 0): ?>
        <div class="garcom-vazio"><span class="garcom-vazio-icone" aria-hidden="true"><?= Icon::svg('book-open', 'icon-lg') ?></span><strong>Cardápio vazio</strong><p>O gerente ainda não cadastrou produtos.</p></div>
    <?php endif; ?>
    <?php foreach ($cardapio as $cat): ?>
        <section class="produtos-grupo" data-grupo>
            <h2><?= $e($cat['nome']) ?></h2>
            <ul class="produtos-lista">
                <?php foreach ($cat['produtos'] as $p): ?>
                    <li class="produto-linha<?= $p['disponivel'] ? '' : ' is-indisponivel' ?>" data-produto data-nome="<?= $e($p['nome'] . ' ' . $p['preco']) ?>" data-tem="<?= $p['disponivel'] ? '1' : '0' ?>">
                        <div class="produto-linha-texto">
                            <strong><?= $e($p['nome']) ?></strong>
                            <?php if ($p['descricao'] !== ''): ?><small><?= $e($p['descricao']) ?></small><?php endif; ?>
                        </div>
                        <div class="produto-linha-fim">
                            <span class="produto-linha-preco"><?= $e($p['preco']) ?></span>
                            <?php require __DIR__ . '/_estado-produto.php'; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
    <p class="garcom-nenhuma" data-busca-nenhum hidden>Nenhum produto encontrado.</p>
</div>
