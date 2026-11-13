<?php
/**
 * Topo comum das paginas legais. Espera: $legalTitle e $legalLead. Define $emailPrivacidade (canal do titular) para o texto das paginas.
 */
use Cardapim\Core\Config;
use Cardapim\Core\Icon;

$escLegal = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$hostLegal = (string) (parse_url(Config::get('APP_URL', 'https://deliveryzim.com.br'), PHP_URL_HOST) ?: 'deliveryzim.com.br');
$emailPrivacidade = Config::get('PRIVACIDADE_EMAIL', 'privacidade@' . preg_replace('/^www\./', '', $hostLegal));
$emailPrivacidadeHtml = '<a href="mailto:' . $escLegal($emailPrivacidade) . '">' . $escLegal($emailPrivacidade) . '</a>';
?>
<header class="legal-hero" data-reveal>
    <h1><?= $escLegal($legalTitle) ?></h1>
    <p class="legal-lead"><?= $escLegal($legalLead) ?></p>
    <p class="legal-date"><?= Icon::svg('calendar', 'icon-sm') ?>Atualizada em 12 de novembro de 2026</p>
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
