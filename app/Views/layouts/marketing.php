<?php
/** @var string $content */
$title = htmlspecialchars($pageTitle ?? 'Cardapim', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$description = htmlspecialchars($pageDescription ?? 'Gestão de restaurantes do jeito simples.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102b23">
    <meta name="description" content="<?= $description ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:description" content="<?= $description ?>">
    <title><?= $title ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/site.css">
    <script src="/assets/js/site.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="site-header" id="topo">
        <div class="shell navbar">
            <a class="brand" href="/" aria-label="Cardapim, página inicial">
                <span class="brand-icon" aria-hidden="true"><span class="brand-leaf"></span></span>
                <span>cardapim<span class="brand-period">.</span></span>
            </a>
            <nav class="nav-links" id="main-navigation" aria-label="Navegação principal">
                <a href="/#recursos">Recursos</a>
                <a href="/#como-funciona">Como funciona</a>
                <a href="/#para-quem">Para quem é</a>
            </nav>
            <div class="nav-actions">
                <a class="nav-login" href="/login">Entrar <span class="nav-login-arrow" aria-hidden="true">↗</span></a>
                <a class="button button-small button-lime nav-signup" href="/cadastro">Começar agora <span aria-hidden="true">↗</span></a>
                <button class="menu-toggle" type="button" aria-label="Abrir menu" aria-controls="main-navigation" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>
    </header>
    <main id="conteudo"><?= $content ?></main>
    <footer class="site-footer">
        <div class="shell footer-wrap">
            <a class="brand brand-footer" href="/" aria-label="Cardapim">
                <span class="brand-icon" aria-hidden="true"><span class="brand-leaf"></span></span>
                <span>cardapim<span class="brand-period">.</span></span>
            </a>
            <span>Menos complicação. Mais restaurante.</span>
            <span>© <?= date('Y') ?> Cardapim</span>
        </div>
    </footer>
</body>
</html>
