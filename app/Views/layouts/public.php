<?php
/** @var string $content */
use Cardapim\Core\Icon;
use Cardapim\Services\Configuracoes;

// Layout tolerante a falhas: 404, legais e acesso continuam legiveis sem MySQL.
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$logoPreta = Configuracoes::logoOuPadrao('logo_preta');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$favicon = Configuracoes::logoOuPadrao('icone');
$textoRodape = Configuracoes::textoOuPadrao('texto_rodape', 'Menos complicação. Mais restaurante.');
$title = $escape((string) ($pageTitle ?? marca()));
$description = $escape((string) ($pageDescription ?? marca() . ', gestão de restaurantes.'));
$styles = ['css/pages.css', ...($pageStyles ?? [])];
$scripts = $pageScripts ?? [];
// Paginas legais abertas pelo cardapio de um restaurante: so "Voltar ao cardapio", sem links do site.
[$cardapioUrl, $cardapioNome] = $voltarCardapio ?? [null, null];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-public">
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<?php if ($cardapioUrl !== null): ?>
    <header class="site-header site-header--light">
        <div class="shell navbar">
            <span class="brand"><img src="<?= $escape($logoPreta) ?>" width="156" height="50" alt="<?= marca_html() ?>"></span>
            <nav class="nav-actions" aria-label="Voltar">
                <a class="btn btn-wine btn-sm" href="<?= $escape($cardapioUrl) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Voltar ao cardápio</a>
            </nav>
        </div>
    </header>
    <main id="conteudo" class="public-main"><?= $content ?></main>
    <footer class="rodape-cardapio">
        <p>Cardápio de <?= $escape((string) $cardapioNome) ?> · Pedidos com <?= marca_html() ?></p>
        <button class="botao-link" type="button" data-consentimento-abrir>Preferências de cookies</button>
    </footer>
<?php else: ?>
    <header class="site-header site-header--light">
        <div class="shell navbar">
            <a class="brand" href="/" aria-label="<?= marca_html() ?>, voltar ao início">
                <img src="<?= $escape($logoPreta) ?>" width="156" height="50" alt="<?= marca_html() ?>">
            </a>
            <nav class="nav-actions" aria-label="Acesso rápido">
                <a class="nav-login nav-compact" href="/catalogo"><?= Icon::svg('store', 'icon-sm') ?><span class="nav-label">Restaurantes</span></a>
                <a class="nav-login nav-plans" href="/#planos"><?= Icon::svg('wallet', 'icon-sm') ?>Planos</a>
                <a class="nav-login nav-compact" href="/login"><?= Icon::svg('log-in', 'icon-sm') ?><span class="nav-label">Entrar</span></a>
                <a class="btn btn-wine btn-sm" href="/cadastro">Criar conta</a>
            </nav>
        </div>
    </header>
    <main id="conteudo" class="public-main"><?= $content ?></main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/../partials/consentimento.php'; ?>
</body>
</html>
