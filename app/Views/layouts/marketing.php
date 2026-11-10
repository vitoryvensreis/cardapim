<?php
/** @var string $content */
use Cardapim\Core\AssetUrl;
use Cardapim\Services\Configuracoes;

$brandColorida = htmlspecialchars(Configuracoes::logo('logo_colorida'), ENT_QUOTES, 'UTF-8');
$brandBranca = htmlspecialchars(Configuracoes::logo('logo_branca'), ENT_QUOTES, 'UTF-8');
$icone = htmlspecialchars(Configuracoes::logo('icone'), ENT_QUOTES, 'UTF-8');
$textoRodape = htmlspecialchars(Configuracoes::get('texto_rodape') ?? '', ENT_QUOTES, 'UTF-8');
$title = htmlspecialchars($pageTitle ?? (Configuracoes::get('nome_sistema') ?? 'Cardapim'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$description = htmlspecialchars($pageDescription ?? (Configuracoes::get('descricao_padrao') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#651424">
    <meta name="description" content="<?= $description ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:description" content="<?= $description ?>">
    <title><?= $title ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $icone ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= AssetUrl::asset('css/site.css') ?>">
    <script src="<?= AssetUrl::asset('js/site.js') ?>" defer></script>
</head>
<body>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="site-header" id="topo">
        <div class="shell navbar">
            <a class="brand" href="/" aria-label="Cardapim, página inicial">
                <img class="brand-logo" src="<?= $brandColorida ?>" width="176" height="57" alt="Cardapim">
            </a>
            <nav class="nav-links" id="main-navigation" aria-label="Navegação principal">
                <a href="/#recursos">Recursos</a>
                <a href="/#como-funciona">Como funciona</a>
                <a href="/#planos">Planos</a>
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
                <img class="brand-logo" src="<?= $brandBranca ?>" width="176" height="57" alt="Cardapim">
            </a>
            <span><?= $textoRodape ?></span>
            <span>Um produto da Escotilha Produtora · CNPJ 64.069.224/0001-32</span>
            <span>© <?= date('Y') ?> Cardapim</span>
        </div>
    </footer>
</body>
</html>
