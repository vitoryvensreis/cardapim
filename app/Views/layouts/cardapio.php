<?php
/**
 * Layout do cardapio publico (/{slug}): cara de aplicativo, topo enxuto e sem o rodape grande do site.
 * Espera: $content, $restaurante (nome, slug), $pageTitle, $pageDescription.
 * Opcional: $pageScripts/$pageStyles (troca o js/cardapio.js), $voltar [url, rotulo] e $topoFixo (nome sempre no topo).
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Configuracoes;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$favicon = Configuracoes::logoOuPadrao('icone');
$logoPreta = Configuracoes::logoOuPadrao('logo_preta');
$title = $e((string) ($pageTitle ?? 'Cardápio | Cardapim'));
$description = $e((string) ($pageDescription ?? 'Cardápio digital no Cardapim.'));
$styles = ['css/cardapio.css', 'css/meus-pedidos.css', ...($pageStyles ?? [])];
$scripts = ['js/cliente.js', 'js/meus-pedidos.js', ...($pageScripts ?? ['js/cardapio.js'])];
[$voltarUrl, $voltarRotulo] = $voltar ?? ['/catalogo', 'Ver outros restaurantes'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-cardapio" data-cardapio="<?= $e((string) $restaurante['slug']) ?>">
    <a class="skip-link" href="#cardapio">Pular para o cardápio</a>
    <header class="cd-topo<?= !empty($topoFixo) ? ' is-compacto' : '' ?>" data-topo>
        <a class="cd-topo-btn" href="<?= $e($voltarUrl) ?>" aria-label="<?= $e($voltarRotulo) ?>" title="<?= $e($voltarRotulo) ?>"><?= Icon::svg('arrow-left') ?></a>
        <p class="cd-topo-nome" data-topo-nome aria-hidden="true"><?= $e((string) $restaurante['nome']) ?></p>
        <button class="cd-conta" type="button" data-conta aria-haspopup="dialog">
            <?= Icon::svg('circle-user') ?><span data-conta-texto>Entrar</span>
        </button>
    </header>

    <main id="conteudo" class="cd-main"><?= $content ?></main>

    <footer class="cd-rodape">
        <a class="cd-rodape-marca" href="/" aria-label="Cardapim">
            <span>Pedidos com</span><img src="<?= $e($logoPreta) ?>" width="96" height="31" alt="Cardapim" loading="lazy">
        </a>
        <nav aria-label="Links legais">
            <a href="/privacidade">Privacidade</a>
            <a href="/termos-de-uso">Termos de Uso</a>
            <a href="/catalogo">Outros restaurantes</a>
        </nav>
    </footer>

    <?php require __DIR__ . '/../restaurante/_dialogos.php'; ?>
</body>
</html>
