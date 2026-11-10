<?php
/** @var string $content */
use Cardapim\Core\AssetUrl;
use Cardapim\Services\Configuracoes;

$logo = null;
$icone = null;
try {
    $logo = Configuracoes::logo('logo_preta');
    $icone = Configuracoes::logo('icone');
} catch (\Throwable $exception) {
    // A pagina 404 deve continuar acessivel mesmo quando o MySQL estiver indisponivel.
    error_log('[Cardapim] Branding publico indisponivel: ' . get_class($exception));
}
$title = htmlspecialchars((string) ($pageTitle ?? 'Cardapim'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$description = htmlspecialchars((string) ($pageDescription ?? 'Cardapim, gestão de restaurantes.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#651424">
    <meta name="description" content="<?= $description ?>">
    <?php if (!empty($noIndex)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?= $title ?></title>
    <?php if ($icone !== null): ?>
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($icone, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= AssetUrl::asset('css/pages.css') ?>">
</head>
<body class="public-body">
    <a class="public-skip" href="#conteudo">Pular para o conteúdo</a>
    <header class="public-header">
        <div class="public-container public-header-inner">
            <a class="public-brand" href="/" aria-label="Cardapim, voltar ao início">
                <?php if ($logo !== null): ?>
                    <img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="Cardapim" width="162" height="52">
                <?php else: ?>
                    <strong>Cardapim</strong>
                <?php endif; ?>
            </a>
            <nav class="public-header-links" aria-label="Acesso rápido">
                <a href="/#planos">Planos</a>
                <a href="/login">Entrar</a>
                <a class="public-header-cta" href="/cadastro">Criar conta</a>
            </nav>
        </div>
    </header>
    <main id="conteudo" class="public-main">
        <?= $content ?>
    </main>
    <footer class="public-footer">
        <div class="public-container public-footer-inner">
            <div class="public-footer-top">
                <strong>Cardapim</strong>
                <span>Um produto da Escotilha Produtora · CNPJ 64.069.224/0001-32</span>
            </div>
            <nav class="public-footer-links" aria-label="Documentos e políticas">
                <a href="/privacidade">Privacidade</a>
                <a href="/termos-de-uso">Termos de Uso</a>
                <a href="/cookies">Cookies</a>
                <a href="/exclusao-de-dados">Exclusão de Dados</a>
                <a href="/tratamento-de-dados">Tratamento de Dados</a>
            </nav>
            <small>© <?= date('Y') ?> Cardapim. Todos os direitos reservados.</small>
        </div>
    </footer>
</body>
</html>
