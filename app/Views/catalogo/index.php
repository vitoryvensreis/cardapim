<?php
use Cardapim\Core\Icon;

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="catalog-hero" aria-labelledby="catalog-title">
    <div class="shell">
        <span class="kicker kicker-light">Catálogo</span>
        <h1 id="catalog-title">Restaurantes no <em>Cardapim.</em></h1>
        <p>Encontre restaurantes, bares e lanchonetes que já fazem parte da plataforma.</p>
        <form class="catalog-search" action="/catalogo" method="get" role="search" data-catalog-search>
            <label class="sr-only" for="catalog-busca">Buscar restaurante pelo nome</label>
            <?= Icon::svg('search', 'catalog-search-icon') ?>
            <input id="catalog-busca" name="busca" type="search" value="<?= $esc($busca) ?>" placeholder="Buscar restaurante pelo nome" maxlength="80" autocomplete="off" enterkeyhint="search">
            <button class="btn btn-primary btn-sm" type="submit">Buscar</button>
        </form>
    </div>
</section>

<section class="catalog section-catalog" aria-label="Lista de restaurantes">
    <div class="shell">
        <p class="catalog-status" role="status" aria-live="polite" data-catalog-status>
            <?php if ($busca !== ''): ?>Resultados para “<?= $esc($busca) ?>”<?php endif; ?>
        </p>

        <?php if ($indisponivel): ?>
            <div class="catalog-empty" role="alert">
                <span class="catalog-empty-icon"><?= Icon::svg('info', 'icon-lg') ?></span>
                <h2>Não conseguimos carregar os restaurantes agora.</h2>
                <p>Recarregue a página em alguns instantes.</p>
                <a class="btn btn-wine" href="/catalogo"><?= Icon::svg('refresh-cw') ?>Tentar novamente</a>
            </div>
        <?php else: ?>
            <ul class="rest-grid" data-catalog-list data-next-cursor="<?= $esc((string) $proximoCursor) ?>" data-busca="<?= $esc($busca) ?>">
                <?php foreach ($restaurantes as $r): ?>
                    <?php require __DIR__ . '/_card.php'; ?>
                <?php endforeach; ?>
            </ul>

            <div class="catalog-empty" data-catalog-empty="busca"<?= $restaurantes !== [] || $busca === '' ? ' hidden' : '' ?>>
                <span class="catalog-empty-icon"><?= Icon::svg('search', 'icon-lg') ?></span>
                <h2>Nenhum restaurante encontrado.</h2>
                <p>Confira o nome digitado ou <a href="/catalogo">veja todos os restaurantes</a>.</p>
            </div>
            <div class="catalog-empty" data-catalog-empty="vazio"<?= $restaurantes !== [] || $busca !== '' ? ' hidden' : '' ?>>
                <span class="catalog-empty-icon"><?= Icon::svg('store', 'icon-lg') ?></span>
                <h2>O catálogo ainda está vazio.</h2>
                <p>Os primeiros restaurantes aparecem aqui assim que forem ativados.</p>
                <a class="btn btn-wine" href="/cadastro"><?= Icon::svg('user-plus') ?>Cadastrar meu restaurante</a>
            </div>

            <div class="catalog-loader" data-catalog-loader hidden aria-hidden="true">
                <?php for ($i = 0; $i < 3; $i++): ?><span class="rest-skeleton"></span><?php endfor; ?>
            </div>
            <div class="catalog-error" data-catalog-error hidden role="alert">
                <p>Não foi possível carregar mais restaurantes.</p>
                <button class="btn btn-outline btn-sm" type="button" data-catalog-retry><?= Icon::svg('refresh-cw') ?>Tentar de novo</button>
            </div>
            <p class="catalog-end" data-catalog-end<?= $restaurantes === [] || $proximoCursor !== null ? ' hidden' : '' ?>>
                <?= Icon::svg('check', 'icon-sm') ?>Você viu todos os restaurantes.
            </p>

            <?php if ($proximoCursor !== null): ?>
                <a class="btn btn-outline catalog-more" data-catalog-more href="/catalogo?<?= $esc(http_build_query(array_filter(['busca' => $busca, 'cursor' => $proximoCursor]))) ?>">
                    Ver mais restaurantes <?= Icon::svg('arrow-down', 'icon-move-y') ?>
                </a>
            <?php endif; ?>
            <?php if ($paginaSeguinte): ?>
                <a class="text-link-plain" href="/catalogo<?= $busca !== '' ? '?busca=' . $esc(rawurlencode($busca)) : '' ?>">Voltar ao início da lista</a>
            <?php endif; ?>
            <span class="catalog-sentinel" data-catalog-sentinel aria-hidden="true"></span>

            <template data-catalog-template>
                <?php $r = ['nome' => '', 'url' => '#', 'logo' => null, 'iniciais' => '']; require __DIR__ . '/_card.php'; ?>
            </template>
        <?php endif; ?>
    </div>
</section>

<section class="catalog-cta">
    <div class="shell catalog-cta-inner">
        <div>
            <h2>Tem um restaurante?</h2>
            <p>Coloque pedidos, mesas, cozinha e delivery no mesmo ritmo.</p>
        </div>
        <a class="btn btn-primary btn-lg" href="/cadastro">Quero fazer parte <?= Icon::svg('arrow-up-right', 'icon-move') ?></a>
    </div>
</section>
