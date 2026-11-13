<?php
/**
 * Cabecalho HTML comum. Espera: $title, $description (ja escapados), $favicon,
 * $styles (lista de arquivos em assets/css), $scripts (lista em assets/js), $noIndex.
 * $scriptsCedo: scripts pequenos que rodam ANTES de desenhar a pagina (sem defer), ex.: tirar a landing do app instalado.
 * Fontes do Google so com o aceite de cookies; o aviso fica em partials/consentimento.php (incluido no fim do body).
 * $pwa = true liga o app instalavel (manifesto, icones do celular e service worker): login, cadastro e areas da equipe.
 * $seo (opcional): o que e proprio da pagina para Google e redes sociais (Services\Seo\Seo::meta):
 *   imagem (array de Seo::imagemRestaurante), titulo (titulo do link sem a marca), tipo (og:type), jsonLd (lista).
 *   Sem $seo, o link usa a imagem da plataforma (Configuracoes > Google e redes sociais).
 */
use Cardapim\Core\AssetUrl;
use Cardapim\Services\Seo\Seo;

$seoMeta = Seo::meta(is_array($seo ?? null) ? $seo : [], !empty($noIndex));
$seoE = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$seoIconeApp = \Cardapim\Controllers\PwaController::icones()[0]['src'];
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php foreach ($scriptsCedo ?? [] as $scriptCedo): ?>
<script src="<?= AssetUrl::asset($scriptCedo) ?>"></script>
<?php endforeach; ?>
<meta name="theme-color" content="#651424">
<meta name="description" content="<?= $description ?>">
<?php if (!empty($noIndex)): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php if ($seoMeta['canonical'] !== null): ?><link rel="canonical" href="<?= $seoE($seoMeta['canonical']) ?>">
<?php endif; ?>
<?php if ($seoMeta['verificacao'] !== ''): ?><meta name="google-site-verification" content="<?= $seoE($seoMeta['verificacao']) ?>">
<?php endif; ?>
<meta name="application-name" content="<?= marca_html() ?>">
<?php // Cartao do link no WhatsApp, Instagram, Facebook, X e Telegram (Open Graph e Twitter Card). ?>
<meta property="og:type" content="<?= $seoE($seoMeta['tipo']) ?>">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="<?= marca_html() ?>">
<meta property="og:url" content="<?= $seoE($seoMeta['url']) ?>">
<meta property="og:title" content="<?= $seoMeta['titulo'] !== null ? $seoE($seoMeta['titulo']) : $title ?>">
<meta property="og:description" content="<?= $description ?>">
<meta property="og:image" content="<?= $seoE($seoMeta['imagem']['url']) ?>">
<?php if (str_starts_with($seoMeta['imagem']['url'], 'https://')): ?><meta property="og:image:secure_url" content="<?= $seoE($seoMeta['imagem']['url']) ?>">
<?php endif; ?>
<meta property="og:image:type" content="<?= $seoE($seoMeta['imagem']['tipo']) ?>">
<meta property="og:image:width" content="<?= (int) $seoMeta['imagem']['largura'] ?>">
<meta property="og:image:height" content="<?= (int) $seoMeta['imagem']['altura'] ?>">
<meta property="og:image:alt" content="<?= $seoE($seoMeta['imagem']['alt']) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $seoMeta['titulo'] !== null ? $seoE($seoMeta['titulo']) : $title ?>">
<meta name="twitter:description" content="<?= $description ?>">
<meta name="twitter:image" content="<?= $seoE($seoMeta['imagem']['url']) ?>">
<meta name="twitter:image:alt" content="<?= $seoE($seoMeta['imagem']['alt']) ?>">
<title><?= $title ?></title>
<link rel="icon" type="<?= $seoE(Seo::tipoImagem($favicon)) ?>" href="<?= $seoE($favicon) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= $seoE($seoIconeApp) ?>">
<link rel="apple-touch-icon" href="<?= $seoE(\Cardapim\Controllers\PwaController::iconeApple()) ?>">
<?php foreach ($seoMeta['jsonLd'] as $seoJson): ?>
<script type="application/ld+json"><?= $seoJson ?></script>
<?php endforeach; ?>
<?php if (!empty($pwa)): ?>
<link rel="manifest" href="/pwa/manifesto">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= marca_html() ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script src="<?= AssetUrl::asset('js/pwa.js') ?>" defer></script>
<?php endif; ?>
<?php if (\Cardapim\Shared\Consentimento::aceitouTodos()): // Fontes do Google: so com o aceite de cookies (LGPD). ?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" data-fontes-externas>
<?php endif; ?>
<link rel="stylesheet" href="<?= AssetUrl::asset('css/core.css') ?>">
<link rel="stylesheet" href="<?= AssetUrl::asset('css/consentimento.css') ?>">
<?php foreach ($styles ?? [] as $style): ?>
<link rel="stylesheet" href="<?= AssetUrl::asset($style) ?>">
<?php endforeach; ?>
<script src="<?= AssetUrl::asset('js/core.js') ?>" defer></script>
<script src="<?= AssetUrl::asset('js/consentimento.js') ?>" defer></script>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= AssetUrl::asset($script) ?>" defer></script>
<?php endforeach; ?>
