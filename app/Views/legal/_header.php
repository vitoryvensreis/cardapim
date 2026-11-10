<?php use Cardapim\Core\Icon; ?>
<header class="legal-hero" data-reveal>
    <p class="legal-eyebrow"><?= Icon::svg('scale', 'icon-sm') ?>CENTRAL DE INFORMAÇÕES LEGAIS</p>
    <h1><?= htmlspecialchars($legalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p class="legal-lead"><?= htmlspecialchars($legalLead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <p class="legal-date"><?= Icon::svg('file-text', 'icon-sm') ?>Versão inicial · Atualizada em 09/11/2026</p>
    <div class="legal-review-notice" role="note">
        <?= Icon::svg('info') ?>
        <div>
            <strong>Documento em revisão</strong>
            <span>Texto informativo inicial. Dados de contato, endereço e revisão jurídica serão concluídos antes da liberação dos cadastros.</span>
        </div>
    </div>
</header>
<div class="legal-layout">
<nav class="legal-nav" aria-label="Outras políticas e documentos">
    <?php foreach ([
        'privacidade' => ['/privacidade', 'Privacidade', 'shield-check'],
        'termos' => ['/termos-de-uso', 'Termos de Uso', 'file-text'],
        'cookies' => ['/cookies', 'Cookies', 'cookie'],
        'exclusao' => ['/exclusao-de-dados', 'Exclusão de Dados', 'trash'],
        'tratamento' => ['/tratamento-de-dados', 'Tratamento de Dados', 'scale'],
    ] as $key => [$url, $label, $icon]): ?>
        <a href="<?= $url ?>" <?= ($activeLegalPage ?? '') === $key ? 'aria-current="page"' : '' ?>><?= Icon::svg($icon) ?><?= $label ?></a>
    <?php endforeach; ?>
</nav>
