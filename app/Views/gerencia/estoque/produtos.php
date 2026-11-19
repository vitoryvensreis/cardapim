<?php
/**
 * Lista de produtos do cardapio. Espera: $produtos, $disponibilidade, $filtro, $base, $csrf, $contagem, $abaAtual.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Shared\Numero;
use Cardapim\Shared\MidiaUrl;

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
                            <?php if (!empty($p['imagem_path'])): ?>
                                <img class="produto-lista-foto" src="<?= $e(MidiaUrl::publica((string) $p['imagem_path'])) ?>" alt="" loading="lazy" decoding="async" width="48" height="48">
                            <?php else: ?>
                                <span class="produto-lista-foto is-vazia" aria-hidden="true"><?= Icon::svg('image', 'icon-sm') ?></span>
                            <?php endif; ?>
                            <span class="lista-titulo">
                                <a href="<?= $e($base . '/estoque/produtos/' . $id) ?>"><strong><?= $e((string) $p['nome']) ?></strong></a>
                                <small><?= $e($categoria) ?><?= $p['codigo'] ? ' · Cód. ' . $e((string) $p['codigo']) : '' ?></small>
                            </span>
                        </div>
                        <span class="lista-dado" data-rotulo="Preço"><?= $e(Numero::reais((string) $p['preco']) . \Cardapim\Modules\Inventory\QuantidadeVenda::sufixoPreco((string) ($p['unidade_venda'] ?? 'un'))) ?></span>
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
                            <?php elseif (!empty($p['id_insumo_proprio'])): ?>
                                Restam <?= $e(\Cardapim\Modules\Inventory\QuantidadeVenda::quanto((int) ($d['restante'] ?? (int) $d['porcoes'] * 1000), (string) ($d['unidade'] ?? 'un'))) ?>
                            <?php else: ?>
                                Dá para vender <?= $e(\Cardapim\Modules\Inventory\QuantidadeVenda::quanto((int) ($d['restante'] ?? (int) $d['porcoes'] * 1000), (string) ($d['unidade'] ?? 'un'))) ?>
                            <?php endif; ?>
                            <?php if (!empty($p['id_insumo_proprio'])): $siglaProprio = ['un' => 'un', 'kg' => 'kg', 'l' => 'L'][(string) ($p['unidade_venda'] ?? 'un')] ?? 'un'; ?>
                                <form class="repor repor-produto" method="post" action="<?= $e($base . '/estoque/insumos/' . (int) $p['id_insumo_proprio'] . '/repor') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <label class="sr-only" for="chegou-<?= $id ?>">Quanto chegou de <?= $e((string) $p['nome']) ?></label>
                                    <span class="repor-campo">
                                        <input id="chegou-<?= $id ?>" name="quantidade" inputmode="decimal" autocomplete="off" placeholder="Chegou mais" data-mask="quantity" required>
                                        <span aria-hidden="true"><?= $e($siglaProprio) ?></span>
                                    </span>
                                    <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Somar</button>
                                </form>
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
                                  data-confirm="O produto deixará de aparecer no cardápio. Se tiver pedidos ou vínculo com o iFood, será arquivado para preservar o histórico. Se foi publicado pelo Deliveryzim no iFood, a exclusão será sincronizada; itens apenas vinculados serão pausados."
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
