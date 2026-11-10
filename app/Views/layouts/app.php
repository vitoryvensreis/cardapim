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

// Abas: apenas as areas que o cargo pode abrir.
$abas = [];
if ($usuario->cargo === Autorizacao::CARGO_MASTER) {
    $abas[] = ['/painel', 'Painel geral', 'layout-dashboard', 'painel'];
} elseif ($usuario->restauranteSlug !== null) {
    foreach (['gerencia' => ['Gerência', 'layout-dashboard'], 'caixa' => ['Caixa', 'wallet'], 'entregas' => ['Entregas', 'bike']] as $chave => [$rotulo, $icone]) {
        if (in_array($usuario->cargo, Autorizacao::AREAS_RESTAURANTE[$chave], true)) {
            $abas[] = [$usuario->urlRestaurante($chave), $rotulo, $icone, $chave];
        }
    }
}
$iniciais = mb_strtoupper(mb_substr($usuario->nome, 0, 1) . mb_substr($usuario->sobrenome, 0, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-app">
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="app-top">
        <div class="app-top-inner">
            <a class="app-brand" href="<?= $e(Autorizacao::destino($usuario)) ?>" aria-label="Cardapim, minha área">
                <img src="<?= $e($logoBranca) ?>" width="120" height="39" alt="Cardapim">
            </a>
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
        <?php if (count($abas) > 1): ?>
            <nav class="app-tabs" aria-label="Áreas disponíveis">
                <?php foreach ($abas as [$href, $rotulo, $icone, $chave]): ?>
                    <a href="<?= $e($href) ?>"<?= ($area ?? '') === $chave ? ' aria-current="page"' : '' ?>><?= Icon::svg($icone, 'icon-sm') ?><?= $e($rotulo) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>
    <main id="conteudo" class="app-main"><?= $content ?></main>
</body>
</html>
