<?php
/** @var string $content */
use Cardapim\Core\Icon;
use Cardapim\Services\Configuracoes;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$logoColorida = Configuracoes::logoOuPadrao('logo_colorida');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$favicon = Configuracoes::logoOuPadrao('icone');
$textoRodape = Configuracoes::textoOuPadrao('texto_rodape', 'Menos complicação. Mais restaurante.');
$title = $escape($pageTitle ?? Configuracoes::textoOuPadrao('nome_sistema', marca()));
$description = $escape($pageDescription ?? Configuracoes::textoOuPadrao('descricao_padrao', ''));
$styles = ['css/site.css'];
$scripts = ['js/site.js'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-marketing">
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="site-header" data-site-header>
        <div class="shell navbar">
            <a class="brand" href="/" aria-label="<?= marca_html() ?>, página inicial">
                <img src="<?= $escape($logoColorida) ?>" width="156" height="50" alt="<?= marca_html() ?>">
            </a>
            <nav class="nav-links" id="main-navigation" aria-label="Navegação principal">
                <a href="/#recursos" data-nav-link>Recursos</a>
                <a href="/#como-funciona" data-nav-link>Como funciona</a>
                <a href="/#para-quem" data-nav-link>Para quem é</a>
                <a href="/#planos" data-nav-link>Planos</a>
                <a href="/catalogo">Restaurantes</a>
                <a class="nav-links-mobile-only" href="/login">Entrar <?= Icon::svg('log-in') ?></a>
            </nav>
            <div class="nav-actions">
                <a class="nav-login" href="/login"><?= Icon::svg('log-in', 'icon-sm') ?>Entrar</a>
                <a class="btn btn-primary btn-sm nav-signup" href="/cadastro">Começar agora <?= Icon::svg('arrow-up-right', 'icon-move') ?></a>
                <button class="menu-toggle" type="button" aria-label="Abrir menu" aria-controls="main-navigation" aria-expanded="false" data-menu-toggle>
                    <?= Icon::svg('menu', 'icon-open') ?><?= Icon::svg('x', 'icon-close') ?>
                </button>
            </div>
        </div>
    </header>
    <main id="conteudo"><?= $content ?></main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
