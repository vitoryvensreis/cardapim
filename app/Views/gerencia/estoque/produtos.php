<?php
/**
 * Lista de produtos do cardapio. Espera: $produtos, $disponibilidade, $filtro, $base, $csrf, $contagem, $abaAtual.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filtros = ['todos' => 'Todos', 'a-venda' => 'À venda', 'indisponiveis' => 'Fora de venda'];
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Estoque</h1>
            <p>Produtos que aparecem no seu cardápio.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-primary btn-sm" href="<?= $e($base . '/estoque/produtos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo produto</a>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>

    <?php if ($contagem['produtos'] === 0): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('hamburger') ?>
                <strong>Nenhum produto cadastrado</strong>
                <p>Cadastre o que você vende: lanches, porções, bebidas. Você pode ligar cada produto aos insumos para o estoque baixar sozinho.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary" href="<?= $e($base . '/estoque/produtos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar produto</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="ferramentas">
            <label class="busca">
                <?= Icon::svg('search', 'icon-sm') ?>
                <span class="sr-only">Buscar produto</span>
                <input type="search" placeholder="Buscar pelo nome ou categoria" data-filtro="#lista-produtos" data-filtro-vazio="#produtos-nenhum" autocomplete="off">
            </label>
            <nav class="filtros" aria-label="Filtrar produtos">
                <?php foreach ($filtros as $chave => $rotulo): ?>
                    <a href="<?= $e($base . '/estoque/produtos' . ($chave === 'todos' ? '' : '?filtro=' . $chave)) ?>"<?= $filtro === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ($produtos === []): ?>
            <div class="bloco"><div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong>Nenhum produto neste filtro</strong><p><a href="<?= $e($base . '/estoque/produtos') ?>">Ver todos os produtos</a></p></div></div>
        <?php else: ?>
            <ul class="lista lista-produtos" id="lista-produtos">
                <li class="lista-cabecalho" aria-hidden="true"><span>Produto</span><span>Preço</span><span>Situação</span><span>Estoque</span><span class="lista-cab-acoes">Ações</span></li>
                <?php foreach ($produtos as $p):
                    $id = (int) $p['id'];
                    $d = $disponibilidade[$id] ?? ['disponivel' => false, 'motivo' => null, 'porcoes' => null, 'faltando' => []];
                    $pausado = (int) $p['disponivel'] !== 1;
                    $categoria = (string) ($p['categoria'] ?? 'Sem categoria');
                ?>
                    <li class="lista-item" data-filtro-item="<?= $e($p['nome'] . ' ' . $categoria) ?>">
                        <div class="lista-principal">
                            <span class="lista-icone" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $p['nome']), 0, 2))) ?></span>
                            <span class="lista-titulo">
                                <a href="<?= $e($base . '/estoque/produtos/' . $id) ?>"><strong><?= $e((string) $p['nome']) ?></strong></a>
                                <small><?= $e($categoria) ?><?= $p['codigo'] ? ' · Cód. ' . $e((string) $p['codigo']) : '' ?></small>
                            </span>
                        </div>
                        <span class="lista-dado" data-rotulo="Preço"><?= $e(Numero::reais((string) $p['preco'])) ?></span>
                        <span class="lista-dado" data-rotulo="Situação">
                            <?php if ($d['disponivel']): ?>
                                <span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>À venda</span>
                            <?php else: ?>
                                <span class="etiqueta <?= $pausado ? 'etiqueta-neutra' : 'etiqueta-alerta' ?>" title="<?= $e(AnaliseEstoque::motivoTexto($d)) ?>"><?= Icon::svg($pausado ? 'power' : 'ban') ?><?= $pausado ? 'Pausado' : 'Sem estoque' ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="lista-dado" data-rotulo="Estoque">
                            <?php if ((int) $p['controla_estoque'] !== 1): ?>
                                <span class="texto-suave">Não controla</span>
                            <?php elseif (!$d['disponivel'] && !$pausado): ?>
                                <span class="texto-alerta"><?= $e(AnaliseEstoque::motivoTexto($d)) ?></span>
                            <?php else: ?>
                                Dá para vender <?= (int) $d['porcoes'] ?>
                            <?php endif; ?>
                        </span>
                        <div class="lista-acoes">
                            <form method="post" action="<?= $e($base . '/estoque/produtos/' . $id . '/pausar') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn-icone" type="submit" title="<?= $pausado ? 'Voltar a vender' : 'Pausar a venda' ?>">
                                    <?= Icon::svg($pausado ? 'rotate-ccw' : 'power', 'icon-sm') ?><span><?= $pausado ? 'Vender' : 'Pausar' ?></span>
                                </button>
                            </form>
                            <a class="btn-icone" href="<?= $e($base . '/estoque/produtos/' . $id) ?>"><?= Icon::svg('pencil', 'icon-sm') ?><span>Editar</span></a>
                            <form method="post" action="<?= $e($base . '/estoque/produtos/' . $id . '/excluir') ?>"
                                  data-confirm="O produto e a ficha técnica dele serão apagados. Isso não pode ser desfeito."
                                  data-confirm-titulo="Excluir &quot;<?= $e((string) $p['nome']) ?>&quot;?" data-confirm-acao="Excluir produto" data-confirm-perigo="1">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn-icone is-perigo" type="submit" aria-label="Excluir <?= $e((string) $p['nome']) ?>"><?= Icon::svg('trash', 'icon-sm') ?></button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="lista-vazia" id="produtos-nenhum" hidden>Nenhum produto encontrado com essa busca.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
