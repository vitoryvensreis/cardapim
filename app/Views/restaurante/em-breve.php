<?php
use Cardapim\Core\Icon;

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="restaurant-soon" aria-labelledby="restaurant-title">
    <div class="restaurant-soon-card" data-reveal>
        <span class="rest-avatar rest-avatar-lg">
            <?php if ($logo !== null): ?>
                <img src="<?= $esc($logo) ?>" alt="" width="96" height="96">
            <?php else: ?>
                <span class="rest-initials" aria-hidden="true"><?= $esc($iniciais) ?></span>
            <?php endif; ?>
        </span>
        <h1 id="restaurant-title"><?= $esc($restaurante['nome']) ?></h1>
        <p class="restaurant-address"><?= Icon::svg('store', 'icon-sm') ?>cardapim · /<?= $esc($restaurante['slug']) ?></p>
        <div class="notice notice-info" role="status">
            <?= Icon::svg('cooking-pot') ?>
            <div>
                <strong>Cardápio digital em preparação</strong>
                <p>Em breve você poderá ver o cardápio e fazer pedidos deste restaurante por aqui.</p>
            </div>
        </div>
        <a class="btn btn-wine" href="/catalogo"><?= Icon::svg('arrow-left') ?>Ver outros restaurantes</a>
    </div>
</section>
