<?php
/**
 * Layout do painel do gerente: menu lateral + area de conteudo.
 * Espera: $content, $usuario (UsuarioLogado), $csrf, $restaurante (id, nome, slug), $ativo (chave do menu).
 * @var \Cardapim\Services\Auth\UsuarioLogado $usuario
 */
use Cardapim\Core\Icon;
use Cardapim\Realtime\Canais;
use Cardapim\Realtime\TempoReal;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Configuracoes;
use Cardapim\Services\Painel\MenuGerencia;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$favicon = Configuracoes::logoOuPadrao('icone');
$logoBranca = Configuracoes::logoOuPadrao('logo_branca');
$title = $e((string) ($pageTitle ?? 'Gerência | Cardapim'));
$description = $e('Painel do gerente no Cardapim.');
$noIndex = true;
$styles = ['css/pages.css', 'css/app.css', 'css/painel.css'];
$tempoReal = TempoReal::ativo();
$scripts = ['js/painel.js', 'js/confirmar.js', ...($tempoReal ? ['js/tempo-real.js'] : []), ...($pageScripts ?? [])];
$slug = (string) $restaurante['slug'];
$iniciais = mb_strtoupper(mb_substr($usuario->nome, 0, 1) . mb_substr($usuario->sobrenome, 0, 1));
$tituloAtual = $ativo !== null && isset(MenuGerencia::SECOES[$ativo]) ? MenuGerencia::SECOES[$ativo]['rotulo']
    : (MenuGerencia::AREAS['@' . $ativo]['rotulo'] ?? 'Gerência');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-painel"<?php if ($tempoReal): ?> data-tempo-real="<?= $e('/api/v1/tempo-real/ticket?restaurante=' . rawurlencode((string) $restaurante['slug'])) ?>" data-tempo-real-canais="<?= $e(implode(',', Canais::daSecao($ativo))) ?>"<?php endif; ?>>
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <div class="painel">
        <aside class="painel-side" id="painel-menu" aria-label="Menu da gerência" data-painel-menu>
            <div class="painel-side-top">
                <a class="painel-brand" href="<?= $e(MenuGerencia::url($slug, 'visao-geral')) ?>" aria-label="Cardapim, visão geral">
                    <img src="<?= $e($logoBranca) ?>" width="128" height="41" alt="Cardapim">
                </a>
                <button class="painel-close" type="button" aria-label="Fechar menu" data-painel-fechar><?= Icon::svg('x') ?></button>
            </div>
            <div class="painel-restaurante">
                <span class="painel-restaurante-icone"><?= Icon::svg('store', 'icon-sm') ?></span>
                <span><small>Restaurante</small><strong><?= $e((string) $restaurante['nome']) ?></strong></span>
            </div>
            <nav class="painel-nav" aria-label="Seções da gerência">
                <?php foreach (MenuGerencia::GRUPOS as $grupo => $chaves): ?>
                    <?php if ($grupo !== ''): ?><p class="painel-grupo"><?= $e($grupo) ?></p><?php endif; ?>
                    <ul>
                        <?php foreach ($chaves as $chave):
                            $area = MenuGerencia::AREAS[$chave] ?? null;
                            $rotulo = $area['rotulo'] ?? MenuGerencia::SECOES[$chave]['rotulo'];
                            $icone = $area['icone'] ?? MenuGerencia::SECOES[$chave]['icone'];
                            $marcado = $ativo === ($area['area'] ?? $chave);
                        ?>
                            <li>
                                <a class="painel-link" href="<?= $e(MenuGerencia::url($slug, $chave)) ?>"<?= $marcado ? ' aria-current="page"' : '' ?>>
                                    <?= Icon::svg($icone) ?>
                                    <span><?= $e($rotulo) ?></span>
                                    <?php if ($area !== null && $area['area'] === 'caixa'): ?><span class="painel-tag">Tela cheia</span><?php endif; ?>
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
                    <span class="painel-breadcrumb-rest"><?= $e((string) $restaurante['nome']) ?></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                    <strong><?= $e($tituloAtual) ?></strong>
                </p>
                <?php if ($tempoReal): ?>
                    <p class="tempo-real" data-tempo-real-status data-estado="conectando" title="Quando algo muda, o Cardapim avisa nesta tela sem precisar recarregar.">
                        <span class="tempo-real-icone tempo-real-icone-on"><?= Icon::svg('wifi', 'icon-sm') ?></span>
                        <span class="tempo-real-icone tempo-real-icone-off"><?= Icon::svg('wifi-off', 'icon-sm') ?></span>
                        <span data-tempo-real-texto>Conectando</span>
                    </p>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm painel-caixa" href="<?= $e(MenuGerencia::url($slug, '@caixa')) ?>"><?= Icon::svg('monitor', 'icon-sm') ?>Abrir caixa</a>
            </header>
            <main id="conteudo" class="painel-main">
                <?php if (!empty($flash['mensagem'])): ?>
                    <div class="painel-flash painel-flash-<?= $e((string) $flash['tipo']) ?>" role="<?= ($flash['tipo'] ?? '') === 'erro' ? 'alert' : 'status' ?>" data-flash>
                        <?= Icon::svg(($flash['tipo'] ?? '') === 'erro' ? 'circle-x' : (($flash['tipo'] ?? '') === 'aviso' ? 'info' : 'circle-check')) ?>
                        <p><?= $e((string) $flash['mensagem']) ?></p>
                        <button type="button" class="painel-flash-fechar" aria-label="Fechar aviso" data-flash-fechar><?= Icon::svg('x', 'icon-sm') ?></button>
                    </div>
                <?php endif; ?>
                <?php if ($tempoReal): ?>
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
