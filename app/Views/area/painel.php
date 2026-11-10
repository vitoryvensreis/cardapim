<?php
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="app-shell">
    <section class="app-hero" data-reveal>
        <span class="app-hero-icon"><?= Icon::svg('shield-check', 'icon-lg') ?></span>
        <div>
            <p class="app-hello">Olá, <?= $e($usuario->nome) ?>!</p>
            <h1>Painel geral</h1>
            <p>Controle de toda a plataforma: restaurantes, planos, usuários e saúde do sistema.</p>
        </div>
    </section>

    <h2 class="app-section-title">Atalhos disponíveis</h2>
    <ul class="app-modules">
        <li class="app-module" data-reveal><a class="app-module-link" href="/status"><span class="app-module-icon"><?= Icon::svg('activity') ?></span><strong>Status do sistema</strong><span class="badge badge-ok">Disponível</span></a></li>
        <li class="app-module" data-reveal><a class="app-module-link" href="/catalogo"><span class="app-module-icon"><?= Icon::svg('store') ?></span><strong>Catálogo público</strong><span class="badge badge-ok">Disponível</span></a></li>
    </ul>

    <h2 class="app-section-title">O que vem por aí</h2>
    <ul class="app-modules">
        <?php foreach ([['store', 'Restaurantes'], ['wallet', 'Planos e assinaturas'], ['user', 'Usuários e cargos'], ['file-text', 'Logs de auditoria'], ['plug-zap', 'Integrações']] as [$i, $nome]): ?>
            <li class="app-module" data-reveal>
                <span class="app-module-icon"><?= Icon::svg($i) ?></span>
                <strong><?= $e($nome) ?></strong>
                <span class="badge">Em breve</span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
