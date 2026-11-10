<?php
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?>
<div class="app-shell">
    <section class="app-hero" data-reveal>
        <span class="app-hero-icon"><?= Icon::svg($icone, 'icon-lg') ?></span>
        <div>
            <p class="app-hello">Olá, <?= $e($usuario->nome) ?>!</p>
            <h1><?= $e($titulo) ?></h1>
            <p><?= $e($descricao) ?></p>
        </div>
    </section>

    <div class="notice notice-info" role="status">
        <?= Icon::svg('cooking-pot') ?>
        <div>
            <strong>Esta área está sendo construída</strong>
            <p>Seu acesso já está liberado. As ferramentas abaixo vão aparecer aqui nas próximas etapas.</p>
        </div>
    </div>

    <h2 class="app-section-title">O que vem por aí</h2>
    <ul class="app-modules">
        <?php foreach ($modulos as [$i, $nome]): ?>
            <li class="app-module" data-reveal>
                <span class="app-module-icon"><?= Icon::svg($i) ?></span>
                <strong><?= $e($nome) ?></strong>
                <span class="badge">Em breve</span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
