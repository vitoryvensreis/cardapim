<?php
/**
 * Cabecalho HTML comum. Espera: $title, $description (ja escapados), $favicon,
 * $styles (lista de arquivos em assets/css), $scripts (lista em assets/js), $noIndex.
 * $pwa = true liga o app instalavel (manifesto, icones do celular e service worker): login, cadastro e areas da equipe.
 */
use Cardapim\Core\AssetUrl;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#651424">
<meta name="description" content="<?= $description ?>">
<?php if (!empty($noIndex)): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="application-name" content="<?= marca_html() ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= marca_html() ?>">
<meta property="og:title" content="<?= $title ?>">
<meta property="og:description" content="<?= $description ?>">
<title><?= $title ?></title>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') ?>">
<?php if (!empty($pwa)): ?>
<link rel="manifest" href="/pwa/manifesto">
<link rel="apple-touch-icon" href="<?= AssetUrl::asset('img/pwa/apple-touch-icon.png') ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= marca_html() ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script src="<?= AssetUrl::asset('js/pwa.js') ?>" defer></script>
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= AssetUrl::asset('css/core.css') ?>">
<?php foreach ($styles ?? [] as $style): ?>
<link rel="stylesheet" href="<?= AssetUrl::asset($style) ?>">
<?php endforeach; ?>
<script src="<?= AssetUrl::asset('js/core.js') ?>" defer></script>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= AssetUrl::asset($script) ?>" defer></script>
<?php endforeach; ?>
