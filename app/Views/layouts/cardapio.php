<?php
/**
 * Layout do cardapio publico (/{slug}): cara de aplicativo, topo enxuto e sem o rodape grande do site.
 * Espera: $content, $restaurante (nome, slug), $pageTitle, $pageDescription.
 * Opcional: $pageScripts/$pageStyles (troca o js/cardapio.js), $voltar [url, rotulo] e $topoFixo (nome sempre no topo).
 * Sem links para o catalogo ou outros restaurantes: o cliente fica no restaurante. A seta so aparece nas
 * paginas internas (finalizar e acompanhar), voltando para o cardapio deste restaurante.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Configuracoes;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$favicon = Configuracoes::logoOuPadrao('icone');
$logoPreta = Configuracoes::logoOuPadrao('logo_preta');
$title = $e((string) ($pageTitle ?? 'Cardápio | ' . marca()));
$description = $e((string) ($pageDescription ?? 'Cardápio digital no ' . marca() . '.'));
$styles = ['css/cardapio.css', 'css/meus-pedidos.css', ...($pageStyles ?? [])];
$scripts = ['js/cliente.js', 'js/meus-pedidos.js', ...($pageScripts ?? ['js/cardapio.js'])];
[$voltarUrl, $voltarRotulo] = $voltar ?? [null, null];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="page-cardapio" data-cardapio="<?= $e((string) $restaurante['slug']) ?>">
    <a class="skip-link" href="#cardapio">Pular para o cardápio</a>
    <header class="cd-topo<?= !empty($topoFixo) ? ' is-compacto' : '' ?>" data-topo>
        <?php if ($voltarUrl !== null): ?>
            <a class="cd-topo-btn" href="<?= $e($voltarUrl) ?>" aria-label="<?= $e($voltarRotulo) ?>" title="<?= $e($voltarRotulo) ?>"><?= Icon::svg('arrow-left') ?></a>
        <?php endif; ?>
        <p class="cd-topo-nome" data-topo-nome aria-hidden="true"><?= $e((string) $restaurante['nome']) ?></p>
        <button class="cd-conta" type="button" data-conta aria-haspopup="dialog">
            <?= Icon::svg('circle-user') ?><span data-conta-texto>Entrar</span>
        </button>
    </header>

    <main id="conteudo" class="cd-main"><?= $content ?></main>

    <footer class="cd-rodape">
        <p class="cd-rodape-marca">
            <span>Pedidos com</span><img src="<?= $e($logoPreta) ?>" width="96" height="31" alt="<?= marca_html() ?>" loading="lazy">
        </p>
        <nav aria-label="Links legais">
            <a href="/privacidade?r=<?= $e(rawurlencode((string) $restaurante['slug'])) ?>">Privacidade</a>
            <a href="/termos-de-uso?r=<?= $e(rawurlencode((string) $restaurante['slug'])) ?>">Termos de Uso</a>
        </nav>
    </footer>

    <?php require __DIR__ . '/../restaurante/_dialogos.php'; ?>
</body>
</html>
