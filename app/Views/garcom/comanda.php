<?php
/**
 * Comanda da mesa: abas das comandas da mesa (uma por pessoa), o que ja foi lancado, total e as acoes (Adicionar / Fechar conta).
 * "Adicionar" abre a folha do cardapio (#adicionar, funciona sem JavaScript pelo :target).
 * "Imprimir": comanda inteira ou so os itens novos, pelo Deliveryzim Agent (imprimir-agent.js).
 * Espera: $c (ComandaService::detalhes), $cardapio, $base, $csrf, $impressaoAtiva, $itensNovos.
 */
use Cardapim\Controllers\GarcomController;
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Shared\Numero;

$pageScripts = [...($pageScripts ?? []), 'js/imprimir-agent.js'];
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$p = $c['pedido'];
$url = $base . '/comandas/' . (int) $p['id'];
$itens = array_values(array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null));
$qtd = array_sum(array_map(static fn (array $i): int => QuantidadeVenda::contaItens(QuantidadeVenda::doBanco($i['quantidade']), (string) ($i['unidade'] ?? 'un')), $itens));
$min = max(0, (int) floor((time() - (int) strtotime((string) $p['criado_em'])) / 60));
$desde = $min < 60 ? $min . ' min' : intdiv($min, 60) . 'h' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT);
?>
<div class="garcom-pagina garcom-comanda" data-regiao="comanda" data-comanda="<?= (int) $p['id'] ?>">
    <?php $novaComandaUrl = $base . '/mesas/' . (int) ($p['id_mesa'] ?? 0) . '/comandas'; require __DIR__ . '/../caixa/_comandas-mesa.php'; ?>

    <section class="comanda-resumo" aria-label="Resumo da conta">
        <div>
            <span class="comanda-resumo-rotulo">Total</span>
            <strong class="comanda-resumo-total"><?= $e(GarcomController::reais($c['total'])) ?></strong>
        </div>
        <ul class="comanda-resumo-dados">
            <li><?= Icon::svg('utensils', 'icon-sm') ?><?= $qtd ?> <?= $qtd === 1 ? 'item' : 'itens' ?></li>
            <li><?= Icon::svg('clock', 'icon-sm') ?><?= $e($desde) ?></li>
            <li><?= Icon::svg('hash', 'icon-sm') ?><?= (int) $p['numero'] ?></li>
        </ul>
    </section>

    <?php if ($itens === []): ?>
        <a class="garcom-vazio garcom-vazio-acao" href="#adicionar" data-abrir-folha>
            <span class="garcom-vazio-icone" aria-hidden="true"><?= Icon::svg('hand-platter', 'icon-lg') ?></span>
            <strong><?= $c['nome'] !== '' ? 'Comanda de ' . $e((string) $c['nome']) : 'Mesa aberta' ?></strong>
            <p>Toque aqui para lançar o primeiro pedido.</p>
        </a>
    <?php else: ?>
        <ul class="comanda-itens">
            <?php foreach ($itens as $i): ?>
                <li class="comanda-item">
                    <span class="comanda-item-qtd"><?= $e(QuantidadeVenda::textoDoBanco($i['quantidade'], (string) ($i['unidade'] ?? 'un'))) ?></span>
                    <div class="comanda-item-texto">
                        <strong><?= $e((string) $i['nome']) ?></strong>
                        <?php if (!empty($i['complementos_texto'])): ?><span class="comanda-item-obs item-complementos"><?= Icon::svg('square-plus', 'icon-sm') ?><?= $e((string) $i['complementos_texto']) ?></span><?php endif; ?>
                        <?php if ($i['observacao']): ?><span class="comanda-item-obs"><?= Icon::svg('sticky-note', 'icon-sm') ?><?= $e((string) $i['observacao']) ?></span><?php endif; ?>
                        <small><?= $e(date('H:i', (int) strtotime((string) $i['criado_em']))) ?><?= $i['autor'] ? ' · ' . $e((string) $i['autor']) : '' ?></small>
                    </div>
                    <span class="comanda-item-valor"><?= $e(Numero::reais((string) $i['total'])) ?></span>
                    <?php $idRemover = 'remover-item-' . (int) $i['id']; ?>
                    <input type="checkbox" id="<?= $e($idRemover) ?>" class="comanda-item-alternar sr-only" aria-controls="<?= $e($idRemover) ?>-form">
                    <label for="<?= $e($idRemover) ?>" class="comanda-item-remover" title="Remover item"><?= Icon::svg('trash', 'icon-sm') ?><span class="sr-only">Remover <?= $e((string) $i['nome']) ?></span></label>
                        <form method="post" action="<?= $e($url . '/itens/' . (int) $i['id'] . '/cancelar') ?>" class="remover-form" id="<?= $e($idRemover) ?>-form">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <fieldset>
                                <legend>Por que remover?</legend>
                                <label class="chip-opcao"><input type="radio" name="motivo" value="Lançado errado" checked><span>Lancei errado</span></label>
                                <label class="chip-opcao"><input type="radio" name="motivo" value="Cliente desistiu"><span>Cliente desistiu</span></label>
                                <label class="chip-opcao"><input type="radio" name="motivo" value="Demorou demais"><span>Demorou</span></label>
                            </fieldset>
                            <button class="btn btn-sm btn-danger" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Remover</button>
                        </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($itens !== [] && ($impressaoAtiva ?? false)): $novos = (int) ($itensNovos ?? 0); ?>
        <details class="comanda-imprimir">
            <summary><?= Icon::svg('printer', 'icon-sm') ?>Imprimir</summary>
            <form method="post" action="<?= $e($url . '/imprimir') ?>" class="comanda-imprimir-opcoes" data-imprimir-agent>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <p>Sai na impressora do caixa, com a <?= $e((string) $c['rotulo']) ?> no topo.</p>
                <button class="comanda-imprimir-opcao" type="submit" name="modo" value="toda">
                    <?= Icon::svg('receipt') ?><span><strong>Comanda inteira</strong><small>Todos os itens e o total até agora</small></span>
                </button>
                <button class="comanda-imprimir-opcao" type="submit" name="modo" value="novos"<?= $novos === 0 ? ' disabled data-sempre-desligado' : '' ?>>
                    <?= Icon::svg('square-plus') ?><span><strong>Só os itens novos<?= $novos > 0 ? ' (' . $novos . ')' : '' ?></strong><small><?= $novos > 0 ? 'O que foi lançado depois da última impressão' : 'Nenhum item novo desde a última impressão' ?></small></span>
                </button>
            </form>
        </details>
    <?php endif; ?>

    <?php if ($c['pago'] === 0): ?>
        <details class="comanda-cancelar">
            <summary><?= Icon::svg('ban', 'icon-sm') ?>Cancelar comanda</summary>
            <form method="post" action="<?= $e($url . '/cancelar') ?>" class="remover-form" data-confirm="<?= count($c['irmas']) > 1 ? ($itens !== [] ? 'Os itens saem da conta e o estoque volta. As outras comandas da mesa continuam abertas.' : 'As outras comandas da mesa continuam abertas.') : ($itens !== [] ? 'Os itens saem da conta, o estoque volta e a mesa fica livre.' : 'A mesa fica livre.') ?>" data-confirm-titulo="Cancelar a <?= $e((string) $c['rotulo']) ?>?" data-confirm-acao="Cancelar comanda" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <fieldset>
                    <legend>Por que cancelar?</legend>
                    <label class="chip-opcao"><input type="radio" name="motivo" value="Mesa aberta por engano"<?= $itens === [] ? ' checked' : '' ?>><span>Abri por engano</span></label>
                    <label class="chip-opcao"><input type="radio" name="motivo" value="Cliente foi embora"<?= $itens !== [] ? ' checked' : '' ?>><span>Cliente foi embora</span></label>
                    <label class="chip-opcao"><input type="radio" name="motivo" value="Cliente desistiu"><span>Cliente desistiu</span></label>
                </fieldset>
                <button class="btn btn-sm btn-danger" type="submit"><?= Icon::svg('ban', 'icon-sm') ?>Cancelar comanda</button>
            </form>
        </details>
    <?php endif; ?>

    <div class="garcom-acoes">
        <a class="btn btn-primary btn-lg" href="#adicionar" data-abrir-folha><?= Icon::svg('plus') ?>Adicionar</a>
        <?php if ($c['total'] > 0): ?>
            <a class="btn btn-wine btn-lg" href="<?= $e($url . '/conta') ?>"><?= Icon::svg('receipt') ?>Fechar conta</a>
        <?php else: ?>
            <span class="btn btn-wine btn-lg is-desligado" aria-disabled="true"><?= Icon::svg('receipt') ?>Fechar conta</span>
        <?php endif; ?>
    </div>
</div>

<section class="gc-folha" id="adicionar" aria-labelledby="folha-titulo" data-folha tabindex="-1">
    <form method="post" action="<?= $e($url . '/itens') ?>" class="gc-folha-caixa" data-lancar>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <header class="gc-folha-topo">
            <a class="garcom-voltar" href="#" aria-label="Fechar" data-fechar-folha><?= Icon::svg('x') ?></a>
            <h2 id="folha-titulo">Adicionar · <?= $e((string) $c['rotulo']) ?></h2>
        </header>
        <div class="gc-folha-busca">
            <label class="busca-campo">
                <?= Icon::svg('search', 'icon-sm') ?>
                <span class="sr-only">Buscar produto</span>
                <input type="search" placeholder="Buscar produto" autocomplete="off" data-busca-produto>
            </label>
            <?php if (count($cardapio) > 1): ?>
                <nav class="chips-categorias" aria-label="Categorias" data-categorias>
                    <?php foreach ($cardapio as $cat): ?><a class="chip" href="#cat-<?= $e($cat['id']) ?>" data-ir-categoria><?= $e($cat['nome']) ?></a><?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>
        <div class="gc-folha-lista" data-folha-lista>
            <?php if ($cardapio === []): ?>
                <div class="garcom-vazio"><span class="garcom-vazio-icone" aria-hidden="true"><?= Icon::svg('book-open', 'icon-lg') ?></span><strong>Cardápio vazio</strong><p>O gerente ainda não cadastrou produtos.</p></div>
            <?php endif; ?>
            <?php foreach ($cardapio as $cat): ?>
                <section class="produtos-grupo" id="cat-<?= $e($cat['id']) ?>" data-grupo>
                    <h3><?= $e($cat['nome']) ?></h3>
                    <ul class="produtos-lista">
                        <?php foreach ($cat['produtos'] as $p): ?>
                            <?php $porPeso = QuantidadeVenda::porPeso((string) ($p['unidade'] ?? 'un')); ?>
                            <li class="produto-linha<?= $p['disponivel'] ? '' : ' is-indisponivel' ?><?= $porPeso ? ' is-peso' : '' ?>" data-produto data-nome="<?= $e($p['nome']) ?>" data-preco="<?= (int) $p['centavos'] ?>" data-minimo="<?= (int) ($p['minimo'] ?? 0) ?>">
                                <div class="produto-linha-texto">
                                    <strong><?= $e($p['nome']) ?></strong>
                                    <span class="produto-linha-preco"><?= $e($p['preco']) ?></span>
                                    <?php require __DIR__ . '/_estado-produto.php'; ?>
                                </div>
                                <?php if ($p['disponivel'] && $porPeso): ?>
                                    <?php // Por quilo/litro: digita os numeros da balanca (8 4 6 -> 0,846). Vazio = nao lanca. ?>
                                    <label class="passo-peso">
                                        <span class="sr-only"><?= $p['unidade'] === 'l' ? 'Volume em litros' : 'Peso em quilos' ?> de <?= $e($p['nome']) ?></span>
                                        <input type="text" name="qtd[<?= (int) $p['id'] ?>]" inputmode="numeric" autocomplete="off" placeholder="0,000" data-mask="peso" data-peso>
                                        <span aria-hidden="true"><?= $p['unidade'] === 'l' ? 'L' : 'kg' ?></span>
                                    </label>
                                    <div class="produto-obs" data-obs hidden>
                                        <label><?= Icon::svg('sticky-note', 'icon-sm') ?><span class="sr-only">Observação para <?= $e($p['nome']) ?></span>
                                            <input type="text" name="obs[<?= (int) $p['id'] ?>]" maxlength="140" placeholder="Observação (ex.: ao ponto)" autocomplete="off">
                                        </label>
                                    </div>
                                <?php elseif ($p['disponivel']): ?>
                                    <div class="passo" data-passo>
                                        <button type="button" class="passo-btn" data-menos aria-label="Tirar um <?= $e($p['nome']) ?>" hidden><?= Icon::svg('minus') ?></button>
                                        <input class="passo-num" type="number" name="qtd[<?= (int) $p['id'] ?>]" value="0" min="0" max="<?= $p['porcoes'] !== null ? min(99, (int) $p['porcoes']) : 99 ?>" inputmode="numeric" aria-label="Quantidade de <?= $e($p['nome']) ?>" data-qtd>
                                        <button type="button" class="passo-btn passo-mais" data-mais aria-label="Adicionar um <?= $e($p['nome']) ?>"><?= Icon::svg('plus') ?></button>
                                    </div>
                                    <?php if (!empty($p['grupos'])): ?>
                                        <div class="produto-comps" data-comps hidden>
                                            <?php foreach ($p['grupos'] as $g): ?>
                                                <fieldset class="comps-grupo" data-comps-grupo data-max="<?= (int) $g['maximo'] ?>">
                                                    <legend><?= $e($g['nome']) ?> <small><?= $g['minimo'] > 0 ? 'obrigatório' : 'opcional' ?><?= $g['maximo'] > 1 ? ', até ' . (int) $g['maximo'] : '' ?></small></legend>
                                                    <?php foreach ($g['opcoes'] as $o): ?>
                                                        <label class="chip-opcao<?= $o['disponivel'] ? '' : ' is-indisponivel' ?>">
                                                            <input type="checkbox" name="comp[<?= (int) $p['id'] ?>][]" value="<?= (int) $o['id'] ?>" data-centavos="<?= (int) $o['centavos'] ?>"<?= $o['disponivel'] ? '' : ' disabled' ?>>
                                                            <span><?= $e($o['nome']) ?><?= $o['centavos'] > 0 ? ' +' . $e($o['preco']) : '' ?><?= $o['disponivel'] ? '' : ' (acabou)' ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="produto-obs" data-obs hidden>
                                        <label><?= Icon::svg('sticky-note', 'icon-sm') ?><span class="sr-only">Observação para <?= $e($p['nome']) ?></span>
                                            <input type="text" name="obs[<?= (int) $p['id'] ?>]" maxlength="140" placeholder="Observação (ex.: sem cebola)" autocomplete="off">
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
            <p class="garcom-nenhuma" data-busca-nenhum hidden>Nenhum produto com esse nome.</p>
        </div>
        <footer class="gc-folha-rodape">
            <button class="btn btn-primary btn-lg btn-block" type="submit" data-lancar-enviar>
                <?= Icon::svg('send') ?><span data-lancar-texto>Lançar</span>
            </button>
        </footer>
    </form>
</section>
