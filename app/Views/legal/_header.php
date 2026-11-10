<header class="legal-hero">
    <p class="legal-eyebrow">CENTRAL DE INFORMAÇÕES LEGAIS</p>
    <h1><?= htmlspecialchars($legalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p class="legal-lead"><?= htmlspecialchars($legalLead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <p class="legal-date">Versão inicial · Atualizada em 09/11/2026</p>
    <div class="legal-review-notice" role="note">
        <strong>Documento em revisão</strong>
        <span>Texto informativo inicial. Dados de contato, endereço e revisão jurídica serão concluídos antes da liberação dos cadastros.</span>
    </div>
</header>
<nav class="legal-nav" aria-label="Outras políticas e documentos">
    <?php foreach ([
        'privacidade' => ['/privacidade', 'Privacidade'],
        'termos' => ['/termos-de-uso', 'Termos de Uso'],
        'cookies' => ['/cookies', 'Cookies'],
        'exclusao' => ['/exclusao-de-dados', 'Exclusão de Dados'],
        'tratamento' => ['/tratamento-de-dados', 'Tratamento de Dados'],
    ] as $key => [$url, $label]): ?>
        <a href="<?= $url ?>" <?= ($activeLegalPage ?? '') === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
</nav>
