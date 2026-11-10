<?php
/**
 * Estrutura comum dos paineis com menu lateral (gerente do restaurante e administracao da plataforma).
 * Incluido por layouts/gerencia.php e layouts/plataforma.php, que preparam as variaveis abaixo.
 *
 * Espera: $content, $usuario (UsuarioLogado), $csrf, $flash, $pageTitle, $pageScripts,
 *   $painel = [
 *     'descricao' => string, 'menu_rotulo' => string, 'inicio' => url, 'inicio_rotulo' => string,
 *     'contexto' => ['rotulo' => string, 'nome' => string, 'icone' => string],
 *     'grupos' => [titulo => list<[url, rotulo, icone, atual(bool), tag(?string)]>],
 *     'trilha' => string, 'titulo_atual' => string, 'acao_topo' => ?[url, rotulo, icone],
 *     'tempo_real' => ?['ticket' => url, 'canais' => string],
 *   ]
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Configuracoes;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$favicon = Configuracoes::logoOuPadrao('icone');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$title = $e((string) ($pageTitle ?? 'Painel | Cardapim'));
$description = $e($painel['descricao']);
$noIndex = true;
$styles = ['css/pages.css', 'css/app.css', 'css/painel.css', ...($pageStyles ?? [])];
$tempoReal = $painel['tempo_real'] ?? null;
$scripts = ['js/painel.js', 'js/confirmar.js', ...($tempoReal !== null ? ['js/tempo-real.js'] : []), ...($pageScripts ?? [])];
$iniciais = mb_strtoupper(mb_substr($usuario->nome, 0, 1) . mb_substr($usuario->sobrenome, 0, 1));
$acaoTopo = $painel['acao_topo'] ?? null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-painel"<?php if ($tempoReal !== null): ?> data-tempo-real="<?= $e($tempoReal['ticket']) ?>" data-tempo-real-canais="<?= $e($tempoReal['canais']) ?>"<?php endif; ?>>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <div class="painel">
        <aside class="painel-side" id="painel-menu" aria-label="<?= $e($painel['menu_rotulo']) ?>" data-painel-menu>
            <div class="painel-side-top">
                <a class="painel-brand" href="<?= $e($painel['inicio']) ?>" aria-label="<?= $e($painel['inicio_rotulo']) ?>">
                    <img src="<?= $e($logoBranca) ?>" width="128" height="41" alt="Cardapim">
                </a>
                <button class="painel-close" type="button" aria-label="Fechar menu" data-painel-fechar><?= Icon::svg('x') ?></button>
            </div>
            <div class="painel-restaurante">
                <span class="painel-restaurante-icone"><?= Icon::svg($painel['contexto']['icone'], 'icon-sm') ?></span>
                <span><small><?= $e($painel['contexto']['rotulo']) ?></small><strong><?= $e($painel['contexto']['nome']) ?></strong></span>
            </div>
            <nav class="painel-nav" aria-label="<?= $e($painel['menu_rotulo']) ?>">
                <?php foreach ($painel['grupos'] as $grupo => $itens): ?>
                    <?php if ($grupo !== ''): ?><p class="painel-grupo"><?= $e((string) $grupo) ?></p><?php endif; ?>
                    <ul>
                        <?php foreach ($itens as [$url, $rotulo, $icone, $marcado, $tag]): ?>
                            <li>
                                <a class="painel-link" href="<?= $e($url) ?>"<?= $marcado ? ' aria-current="page"' : '' ?>>
                                    <?= Icon::svg($icone) ?>
                                    <span><?= $e($rotulo) ?></span>
                                    <?php if ($tag !== null): ?><span class="painel-tag"><?= $e($tag) ?></span><?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </nav>
            <div class="painel-user">
                <span class="app-avatar" aria-hidden="true"><?= $e($iniciais) ?></span>
                <span class="painel-user-info">
                    <strong><?= $e($usuario->nomeCompleto()) ?></strong>
                    <small><?= $e(Autorizacao::rotuloCargo($usuario->cargo)) ?></small>
                </span>
                <form method="post" action="/sair">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="painel-sair" type="submit" aria-label="Sair da conta" title="Sair"><?= Icon::svg('log-in', 'icon-flip') ?></button>
                </form>
            </div>
        </aside>
        <div class="painel-overlay" data-painel-overlay hidden></div>

        <div class="painel-body">
            <header class="painel-top">
                <button class="painel-toggle" type="button" aria-label="Abrir menu" aria-controls="painel-menu" aria-expanded="false" data-painel-abrir>
                    <?= Icon::svg('menu') ?>
                </button>
                <p class="painel-breadcrumb">
                    <span class="painel-breadcrumb-rest"><?= $e($painel['trilha']) ?></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                    <strong><?= $e($painel['titulo_atual']) ?></strong>
                </p>
                <?php if ($tempoReal !== null): ?>
                    <p class="tempo-real" data-tempo-real-status data-estado="conectando" title="Quando algo muda, o Cardapim avisa nesta tela sem precisar recarregar.">
                        <span class="tempo-real-icone tempo-real-icone-on"><?= Icon::svg('wifi', 'icon-sm') ?></span>
                        <span class="tempo-real-icone tempo-real-icone-off"><?= Icon::svg('wifi-off', 'icon-sm') ?></span>
                        <span data-tempo-real-texto>Conectando</span>
                    </p>
                <?php endif; ?>
                <?php if ($acaoTopo !== null): ?>
                    <a class="btn btn-primary btn-sm painel-caixa" href="<?= $e($acaoTopo[0]) ?>"><?= Icon::svg($acaoTopo[2], 'icon-sm') ?><?= $e($acaoTopo[1]) ?></a>
                <?php endif; ?>
            </header>
            <main id="conteudo" class="painel-main">
                <?php if (!empty($flash['mensagem'])): ?>
                    <div class="painel-flash painel-flash-<?= $e((string) $flash['tipo']) ?>" role="<?= ($flash['tipo'] ?? '') === 'erro' ? 'alert' : 'status' ?>" data-flash>
                        <?= Icon::svg(($flash['tipo'] ?? '') === 'erro' ? 'circle-x' : (($flash['tipo'] ?? '') === 'aviso' ? 'info' : 'circle-check')) ?>
                        <p><?= $e((string) $flash['mensagem']) ?></p>
                        <button type="button" class="painel-flash-fechar" aria-label="Fechar aviso" data-flash-fechar><?= Icon::svg('x', 'icon-sm') ?></button>
                    </div>
                <?php endif; ?>
                <?php if ($tempoReal !== null): ?>
                    <div class="painel-flash painel-flash-aviso tempo-real-aviso" role="status" data-tempo-real-aviso hidden>
                        <?= Icon::svg('refresh-cw') ?>
                        <p data-tempo-real-aviso-texto></p>
                        <button type="button" class="btn btn-outline btn-sm" data-tempo-real-atualizar>Atualizar agora</button>
                        <button type="button" class="painel-flash-fechar" aria-label="Fechar aviso" data-tempo-real-fechar><?= Icon::svg('x', 'icon-sm') ?></button>
                    </div>
                <?php endif; ?>
                <?= $content ?>
            </main>
        </div>
    </div>
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
