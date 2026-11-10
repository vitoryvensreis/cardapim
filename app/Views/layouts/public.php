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
$title = $escape((string) ($pageTitle ?? 'Cardapim'));
$description = $escape((string) ($pageDescription ?? 'Cardapim, gestão de restaurantes.'));
$styles = ['css/pages.css'];
$scripts = $pageScripts ?? [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-public">
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="site-header site-header--light">
        <div class="shell navbar">
            <a class="brand" href="/" aria-label="Cardapim, voltar ao início">
                <img src="<?= $escape($logoPreta) ?>" width="156" height="50" alt="Cardapim">
            </a>
            <nav class="nav-actions" aria-label="Acesso rápido">
                <a class="nav-login nav-plans" href="/#planos"><?= Icon::svg('wallet', 'icon-sm') ?>Planos</a>
                <a class="nav-login" href="/login"><?= Icon::svg('log-in', 'icon-sm') ?>Entrar</a>
                <a class="btn btn-wine btn-sm" href="/cadastro">Criar conta</a>
            </nav>
        </div>
    </header>
    <main id="conteudo" class="public-main"><?= $content ?></main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
