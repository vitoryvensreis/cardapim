<?php
/**
 * Layout do painel do garcom (celular primeiro).
 * - Telas principais (Mesas, Cardapio): topo com o restaurante e menu de abas embaixo (no computador, abas no topo).
 * - Telas de uma mesa (comanda, conta, Pix): topo com "voltar" e o nome da mesa; sem o menu de abas.
 * Espera: $content, $usuario, $csrf, $restaurante, $ativo, $tituloTela, $flash, $base, $tempoReal, $voltar (url ou null).
 * Opcional: $subtitulo, $pageScripts.
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Configuracoes;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$favicon = Configuracoes::logoOuPadrao('icone');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$title = $e((string) ($pageTitle ?? 'Garçom | ' . marca()));
$description = $e((string) ($pageDescription ?? 'Painel do garçom.'));
$styles = ['css/pages.css', 'css/app.css', 'css/painel.css', 'css/caixa.css', 'css/garcom.css'];
$scripts = [...($tempoReal !== null ? ['js/tempo-real.js'] : []), 'js/forms.js', 'js/confirmar.js', ...($pageScripts ?? []), 'js/garcom.js'];
$iniciais = mb_strtoupper(mb_substr($usuario->nome, 0, 1) . mb_substr($usuario->sobrenome, 0, 1));
$detalhe = $voltar !== null;
$abas = [
    'mesas' => [$base, 'Mesas', 'layout-grid'],
    'cardapio' => [$base . '/cardapio', 'Cardápio', 'book-open'],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php $pwa = true; require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-app page-garcom<?= $detalhe ? ' is-detalhe' : '' ?>" data-csrf="<?= $e($csrf) ?>" data-base="<?= $e($base) ?>"<?php if ($tempoReal !== null): ?> data-tempo-real="<?= $e($tempoReal) ?>" data-tempo-real-canais="mesas"<?php endif; ?>>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <?php if ($detalhe): ?>
        <header class="garcom-topo">
            <a class="garcom-voltar" href="<?= $e((string) $voltar) ?>" aria-label="Voltar"><?= Icon::svg('arrow-left') ?></a>
            <div class="garcom-topo-titulo">
                <strong><?= $e((string) $tituloTela) ?></strong>
                <?php if (!empty($subtitulo)): ?><small><?= $e((string) $subtitulo) ?></small><?php endif; ?>
            </div>
            <span class="garcom-avatar" title="<?= $e($usuario->nomeCompleto()) ?>" aria-hidden="true"><?= $e($iniciais) ?></span>
        </header>
    <?php else: ?>
        <header class="app-top">
            <div class="app-top-inner">
                <a class="app-brand" href="<?= $e(Autorizacao::destino($usuario)) ?>" aria-label="<?= marca_html() ?>, mesas">
                    <img src="<?= $e($logoBranca) ?>" width="120" height="39" alt="<?= marca_html() ?>">
                </a>
                <span class="app-context"><?= Icon::svg('store', 'icon-sm') ?><?= $e((string) $restaurante['nome']) ?></span>
                <nav class="garcom-abas" aria-label="Menu do garçom">
                    <?php foreach ($abas as $chave => [$href, $rotulo, $icone]): ?>
                        <a href="<?= $e($href) ?>"<?= $ativo === $chave ? ' aria-current="page"' : '' ?>><?= Icon::svg($icone) ?><span><?= $e($rotulo) ?></span></a>
                    <?php endforeach; ?>
                </nav>
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
    <?php endif; ?>

    <main id="conteudo" class="garcom-main">
        <?php if (!empty($flash['mensagem'])): ?>
            <div class="painel-flash painel-flash-<?= $e((string) $flash['tipo']) ?> garcom-flash" role="<?= ($flash['tipo'] ?? '') === 'erro' ? 'alert' : 'status' ?>" data-flash>
                <?= Icon::svg(($flash['tipo'] ?? '') === 'erro' ? 'circle-x' : (($flash['tipo'] ?? '') === 'aviso' ? 'info' : 'circle-check')) ?>
                <p><?= $e((string) $flash['mensagem']) ?></p>
                <button type="button" class="painel-flash-fechar" aria-label="Fechar aviso" data-flash-fechar><?= Icon::svg('x', 'icon-sm') ?></button>
            </div>
        <?php endif; ?>
        <?php if ($tempoReal !== null): ?>
            <div class="painel-flash painel-flash-aviso tempo-real-aviso garcom-flash" role="status" data-tempo-real-aviso hidden>
                <?= Icon::svg('refresh-cw') ?>
                <p data-tempo-real-aviso-texto></p>
                <button type="button" class="btn btn-outline btn-sm" data-tempo-real-atualizar>Atualizar agora</button>
                <button type="button" class="painel-flash-fechar" aria-label="Fechar aviso" data-tempo-real-fechar><?= Icon::svg('x', 'icon-sm') ?></button>
            </div>
        <?php endif; ?>
        <?= $content ?>
    </main>

    <?php if (!$detalhe): ?>
        <nav class="garcom-barra" aria-label="Menu do garçom">
            <?php foreach ($abas as $chave => [$href, $rotulo, $icone]): ?>
                <a href="<?= $e($href) ?>"<?= $ativo === $chave ? ' aria-current="page"' : '' ?>><?= Icon::svg($icone) ?><span><?= $e($rotulo) ?></span></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

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
