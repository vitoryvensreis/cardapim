<?php
/** Categorias do cardapio. Espera: $categorias, $base, $csrf, $contagem, $abaAtual. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Estoque</h1>
            <p>Categorias organizam o cardápio: Lanches, Porções, Bebidas...</p>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>

    <section class="bloco" aria-labelledby="titulo-nova-categoria">
        <div class="bloco-cabecalho"><h2 id="titulo-nova-categoria"><?= Icon::svg('plus') ?>Nova categoria</h2></div>
        <form class="linha-form" method="post" action="<?= $e($base . '/estoque/categorias') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <label class="sr-only" for="nova-categoria">Nome da nova categoria</label>
            <input class="campo-simples" id="nova-categoria" name="nome" maxlength="80" required placeholder="Exemplo: Lanches" autocomplete="off">
            <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Criar categoria</button>
        </form>
    </section>

    <?php if ($categorias === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('tags') ?>
                <strong>Nenhuma categoria ainda</strong>
                <p>Crie a primeira acima. Você também pode criar uma categoria direto no cadastro do produto.</p>
            </div>
        </section>
    <?php else: ?>
        <ul class="lista lista-categorias">
            <li class="lista-cabecalho" aria-hidden="true"><span>Nome (toque para renomear)</span><span>Produtos</span><span class="lista-cab-acoes">Ações</span></li>
            <?php foreach ($categorias as $c): $id = (int) $c['id']; ?>
                <li class="lista-item">
                    <form class="linha-form" method="post" action="<?= $e($base . '/estoque/categorias/' . $id) ?>" id="renomear-<?= $id ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label class="sr-only" for="categoria-<?= $id ?>">Nome da categoria</label>
                        <input class="campo-simples" id="categoria-<?= $id ?>" name="nome" value="<?= $e($c['nome']) ?>" maxlength="80" required autocomplete="off">
                        <button class="btn-icone" type="submit"><?= Icon::svg('save', 'icon-sm') ?><span>Salvar</span></button>
                    </form>
                    <span class="lista-dado" data-rotulo="Produtos">
                        <?php if ($c['produtos'] > 0): ?>
                            <a href="<?= $e($base . '/estoque/produtos') ?>"><?= (int) $c['produtos'] ?> produto(s)</a>
                        <?php else: ?>
                            <span class="texto-suave">Nenhum produto</span>
                        <?php endif; ?>
                    </span>
                    <div class="lista-acoes">
                        <a class="btn-icone" href="<?= $e($base . '/estoque/produtos/novo?categoria=' . $id) ?>"><?= Icon::svg('plus', 'icon-sm') ?><span>Produto</span></a>
                        <form method="post" action="<?= $e($base . '/estoque/categorias/' . $id . '/excluir') ?>"
                              data-confirm="<?= $c['produtos'] > 0 ? $e('Os ' . $c['produtos'] . ' produto(s) desta categoria continuam no cardápio, mas ficam sem categoria.') : 'A categoria será apagada.' ?>"
                              data-confirm-titulo="Excluir a categoria &quot;<?= $e($c['nome']) ?>&quot;?" data-confirm-acao="Excluir categoria" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn-icone is-perigo" type="submit" aria-label="Excluir categoria <?= $e($c['nome']) ?>"><?= Icon::svg('trash', 'icon-sm') ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
