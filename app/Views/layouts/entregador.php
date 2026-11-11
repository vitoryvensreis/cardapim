<?php
/**
 * Layout do painel do entregador (celular primeiro). Mesmo topo das areas logadas + tempo real (canal entregas).
 * Espera: $content, $usuario, $csrf, $restaurante, $tempoReal (url do ticket ou null).
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Configuracoes;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$favicon = Configuracoes::logoOuPadrao('icone');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$title = $e((string) ($pageTitle ?? 'Entregas | Cardapim'));
$description = $e((string) ($pageDescription ?? 'Painel do entregador.'));
$styles = ['css/pages.css', 'css/app.css', 'css/painel.css', 'css/entregador.css'];
$scripts = [...($tempoReal !== null ? ['js/tempo-real.js'] : []), 'js/confirmar.js', 'js/entregador.js'];
$iniciais = mb_strtoupper(mb_substr($usuario->nome, 0, 1) . mb_substr($usuario->sobrenome, 0, 1));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php $pwa = true; require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-app page-entregador" data-csrf="<?= $e($csrf) ?>" data-slug="<?= $e((string) $restaurante['slug']) ?>" data-usuario="<?= (int) $usuario->id ?>"<?php if ($tempoReal !== null): ?> data-tempo-real="<?= $e($tempoReal) ?>" data-tempo-real-canais="entregas"<?php endif; ?>>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <header class="app-top">
        <div class="app-top-inner">
            <a class="app-brand" href="<?= $e(Autorizacao::destino($usuario)) ?>" aria-label="Cardapim, minhas entregas">
                <img src="<?= $e($logoBranca) ?>" width="120" height="39" alt="Cardapim">
            </a>
            <span class="app-context"><?= Icon::svg('store', 'icon-sm') ?><?= $e((string) $restaurante['nome']) ?></span>
            <div class="app-user">
                <span class="app-avatar" aria-hidden="true"><?= $e($iniciais) ?></span>
                <span class="app-user-info"><strong><?= $e($usuario->nomeCompleto()) ?></strong><small><?= $e(Autorizacao::rotuloCargo($usuario->cargo)) ?></small></span>
                <form method="post" action="/sair" class="app-logout" data-sair>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="app-logout-btn" type="submit" aria-label="Sair da conta"><?= Icon::svg('log-in', 'icon-flip') ?><span>Sair</span></button>
                </form>
            </div>
        </div>
    </header>
    <main id="conteudo" class="app-main entregador-main"><?= $content ?></main>
    <dialog class="confirmar" data-confirmar-dialogo aria-labelledby="confirmar-titulo">
        <form method="dialog" class="confirmar-caixa">
            <span class="confirmar-icone" aria-hidden="true"><?= Icon::svg('info', 'icon-lg') ?></span>
            <h2 id="confirmar-titulo" data-confirmar-titulo>Tem certeza?</h2>
            <p data-confirmar-texto></p>
            <div class="confirmar-acoes">
                <button class="btn btn-outline" value="cancelar" type="submit">Voltar</button>
                <button class="btn btn-wine confirmar-ok" value="confirmar" type="submit" data-confirmar-ok>Confirmar</button>
            </div>
        </form>
    </dialog>
</body>
</html>
