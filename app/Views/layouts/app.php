<?php
/**
 * Layout das areas autenticadas. Espera: $content, $usuario (UsuarioLogado), $csrf, $area.
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Configuracoes;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escape = $e;
$favicon = Configuracoes::logoOuPadrao('icone');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$title = $e((string) ($pageTitle ?? 'Cardapim'));
$description = $e((string) ($pageDescription ?? 'Área restrita do Cardapim.'));
$styles = ['css/pages.css', 'css/app.css'];
$scripts = [];

$iniciais = mb_strtoupper(mb_substr($usuario->nome, 0, 1) . mb_substr($usuario->sobrenome, 0, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php $pwa = true; require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-app">
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="app-top">
        <div class="app-top-inner">
            <a class="app-brand" href="<?= $e(Autorizacao::destino($usuario)) ?>" aria-label="Cardapim, minha área">
                <img src="<?= $e($logoBranca) ?>" width="120" height="39" alt="Cardapim">
            </a>
            <?php if (!empty($voltar)): ?>
                <a class="app-back" href="<?= $e($voltar) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?><span>Voltar à gerência</span></a>
            <?php endif; ?>
            <?php if (!empty($modo)): ?>
                <span class="app-mode"><?= Icon::svg('monitor', 'icon-sm') ?><?= $e($modo) ?></span>
            <?php endif; ?>
            <span class="app-context">
                <?= Icon::svg($usuario->cargo === Autorizacao::CARGO_MASTER ? 'shield-check' : 'store', 'icon-sm') ?>
                <?= $e($usuario->restauranteNome ?? 'Plataforma Cardapim') ?>
            </span>
            <div class="app-user">
                <span class="app-avatar" aria-hidden="true"><?= $e($iniciais) ?></span>
                <span class="app-user-info">
                    <strong><?= $e($usuario->nomeCompleto()) ?></strong>
                    <small><?= $e(Autorizacao::rotuloCargo($usuario->cargo)) ?></small>
                </span>
                <form method="post" action="/sair" class="app-logout">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="app-logout-btn" type="submit" aria-label="Sair da conta"><?= Icon::svg('log-in', 'icon-flip') ?><span>Sair</span></button>
                </form>
            </div>
        </div>
    </header>
    <main id="conteudo" class="app-main"><?= $content ?></main>
</body>
</html>
