<?php
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="app-shell">
    <section class="app-hero" data-reveal>
        <span class="app-hero-icon"><?= Icon::svg('user', 'icon-lg') ?></span>
        <div>
            <p class="app-hello">Olá, <?= $e($usuario->nome) ?>!</p>
            <h1>Minha conta</h1>
            <p>Você entrou como <strong><?= $e(Autorizacao::rotuloCargo($usuario->cargo)) ?></strong>.</p>
        </div>
    </section>
    <div class="notice notice-info" role="status">
        <?= Icon::svg('cooking-pot') ?>
        <div>
            <strong>Sua área ainda está em preparação</strong>
            <p>Assim que estiver pronta, ela aparece aqui automaticamente quando você entrar.</p>
        </div>
    </div>
    <a class="btn btn-outline" href="/catalogo"><?= Icon::svg('store') ?>Ver restaurantes</a>
</div>
