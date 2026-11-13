<?php
/**
 * Comanda da mesa ou do balcao. A mesa pode ter varias comandas (uma por pessoa): abas no topo (_comandas-mesa.php).
 * Computador: cardapio a esquerda, conta a direita. Celular/tablet: abas "Cardapio" e "Conta" + barra fixa com o total.
 * Espera: $c (ComandaService::detalhes), $cardapio, $livres, $caixaAberto, $base, $csrf, $erros, $enviado.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$p = $c['pedido'];
$url = $base . '/comandas/' . (int) $p['id'];
$ativos = array_values(array_filter($c['itens'], static fn (array $i): bool => $i['cancelado_em'] === null));
$qtdItens = array_sum(array_map(static fn (array $i): int => (int) $i['quantidade'], $ativos));
$servicoLigado = Numero::centavos((string) $p['servico_percentual']) > 0;
$pessoas = (int) ($p['pessoas'] ?? 0);
$desde = (int) strtotime((string) $p['criado_em']);
$minutos = max(0, (int) floor((time() - $desde) / 60));
$tempo = $minutos < 60 ? $minutos . ' min' : intdiv($minutos, 60) . 'h' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
?>
<div class="painel-page comanda-pagina" data-comanda>
    <header class="painel-cabecalho comanda-cabecalho">
        <div>
            <h1><?= $e($c['rotulo']) ?></h1>
            <p>Pedido #<?= (int) $p['numero'] ?> · aberta às <?= $e(date('H:i', $desde)) ?> (<?= $e($tempo) ?>)</p>
        </div>
        <div class="painel-acoes">
            <?php if ($c['aberta']): ?>
                <span class="etiqueta etiqueta-marca"><?= Icon::svg('utensils') ?>Conta aberta</span>
            <?php elseif ($p['status'] === 'concluido'): ?>
                <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Conta paga</span>
            <?php else: ?>
                <span class="etiqueta etiqueta-alerta"><?= Icon::svg('circle-x') ?>Cancelada</span>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/mesas') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Mesas</a>
        </div>
    </header>

    <?php $novaComandaUrl = $base . '/mesas/' . (int) ($p['id_mesa'] ?? 0) . '/comandas'; require __DIR__ . '/_comandas-mesa.php'; ?>

    <?php if ($c['aberta']): ?>
        <nav class="segmento comanda-abas" aria-label="Partes da comanda" data-comanda-abas hidden>
            <button type="button" data-comanda-aba="cardapio" aria-pressed="<?= $ativos === [] ? 'true' : 'false' ?>"><?= Icon::svg('book-open', 'icon-sm') ?>Cardápio</button>
            <button type="button" data-comanda-aba="conta" aria-pressed="<?= $ativos === [] ? 'false' : 'true' ?>"><?= Icon::svg('receipt', 'icon-sm') ?>Conta <span class="comanda-aba-qtd" data-regiao="aba-qtd"><?= (int) $qtdItens ?></span></button>
        </nav>
    <?php endif; ?>

    <?php if (is_array($trocoAviso ?? null)): ?>
        <div class="troco-destaque" role="status">
            <?= Icon::svg('hand-coins', 'icon-lg') ?>
            <div><span>Devolva de troco</span><strong><?= $e($r((int) $trocoAviso['troco'])) ?></strong>
                <small>O cliente entregou <?= $e($r((int) $trocoAviso['recebido'])) ?> · conta de <?= $e($r($c['total'])) ?></small></div>
        </div>
    <?php endif; ?>
    <div class="comanda-layout<?= $c['aberta'] ? '' : ' is-fechada' ?>">
        <?php if ($c['aberta']): ?>
            <section class="comanda-cardapio" aria-labelledby="titulo-cardapio" data-comanda-parte="cardapio">
                <h2 class="sr-only" id="titulo-cardapio">Cardápio</h2>
                <?php if ($cardapio['total'] === 0): ?>
                    <div class="bloco"><div class="lista-vazia"><?= Icon::svg('package') ?><strong>O cardápio está vazio</strong><p>O gerente cadastra os produtos em Gerência, Estoque.</p></div></div>
                <?php else: ?>
                    <div class="comanda-busca">
                        <label class="busca">
                            <?= Icon::svg('search', 'icon-sm') ?>
                            <span class="sr-only">Buscar produto</span>
                            <input type="search" placeholder="Buscar produto" autocomplete="off" data-produto-busca>
                        </label>
                        <nav class="chips" aria-label="Categorias" data-produto-categorias>
                            <button type="button" aria-pressed="true" data-categoria="">Tudo</button>
                            <?php foreach ($cardapio['categorias'] as $cat): ?>
                                <button type="button" aria-pressed="false" data-categoria="<?= $e($cat['id']) ?>"><?= $e($cat['nome']) ?></button>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <ul class="produtos-grade" data-produtos>
                        <?php foreach ($cardapio['categorias'] as $cat): foreach ($cat['produtos'] as $prod): ?>
                            <li data-produto-item data-categoria="<?= $e($cat['id']) ?>" data-nome="<?= $e(mb_strtolower($prod['nome'])) ?>">
                                <form method="post" action="<?= $e($url . '/itens') ?>" data-ajax>
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="produto" value="<?= (int) $prod['id'] ?>">
                                    <input type="hidden" name="quantidade" value="1">
                                    <button class="produto-botao" type="submit"<?= $prod['disponivel'] ? '' : ' disabled' ?>
                                            data-produto-abrir data-produto-id="<?= (int) $prod['id'] ?>" data-produto-nome="<?= $e($prod['nome']) ?>" data-produto-centavos="<?= (int) $prod['centavos'] ?>">
                                        <span class="produto-nome"><?= $e($prod['nome']) ?></span>
                                        <span class="produto-preco"><?= $prod['disponivel'] ? $e($prod['preco']) : 'Acabou' ?></span>
                                        <?php if ($prod['disponivel']): ?><span class="produto-mais" aria-hidden="true"><?= Icon::svg('plus', 'icon-sm') ?></span><?php endif; ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; endforeach; ?>
                    </ul>
                    <p class="lista-vazia-filtro" data-produtos-nenhum hidden>Nenhum produto com esse nome.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="comanda-conta" aria-labelledby="titulo-conta" data-comanda-parte="conta">
            <div class="bloco conta" data-regiao="conta">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-conta"><?= Icon::svg('receipt') ?>Conta</h2>
                    <?php if ($ativos !== []): ?><button class="btn btn-outline btn-sm" type="button" data-imprimir><?= Icon::svg('printer', 'icon-sm') ?>Imprimir conta</button><?php endif; ?>
                </div>
                <?php if ($c['itens'] === []): ?>
                    <div class="conta-vazia">
                        <?= Icon::svg('utensils') ?>
                        <p><strong>Nenhum item ainda</strong>Toque nos produtos do cardápio para lançar.</p>
                    </div>
                <?php else: ?>
                    <ul class="conta-itens">
                        <?php foreach ($c['itens'] as $i): $cancelado = $i['cancelado_em'] !== null; ?>
                            <li class="<?= $cancelado ? 'is-cancelado' : '' ?>">
                                <span class="conta-qtd"><?= (int) $i['quantidade'] ?>x</span>
                                <span class="conta-nome"><?= $e((string) $i['nome']) ?>
                                    <?php if ($i['observacao']): ?><small><?= $e((string) $i['observacao']) ?></small><?php endif; ?>
                                    <small class="conta-meta"><?= $e(date('H:i', (int) strtotime((string) $i['criado_em']))) ?><?= $i['autor'] ? ' · ' . $e((string) $i['autor']) : '' ?><?= $cancelado ? ' · Cancelado: ' . $e((string) $i['motivo_cancelamento']) : '' ?></small>
                                </span>
                                <span class="conta-preco"><?= $e(Numero::reais((string) $i['total'])) ?></span>
                                <?php if ($c['aberta'] && !$cancelado): ?>
                                    <button class="conta-cancelar" type="button" data-cancelar-item="<?= $e($url . '/itens/' . (int) $i['id'] . '/cancelar') ?>" data-item-nome="<?= $e((int) $i['quantidade'] . 'x ' . $i['nome']) ?>" aria-label="Cancelar <?= $e((string) $i['nome']) ?>">
                                        <?= Icon::svg('x', 'icon-sm') ?>
                                    </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <dl class="conta-totais">
                    <div><dt>Itens (<?= (int) $qtdItens ?>)</dt><dd><?= $e($r($c['subtotal'])) ?></dd></div>
                    <?php if ($servicoLigado || $c['servico'] > 0): ?><div><dt>Serviço (<?= $e(rtrim(rtrim(number_format((float) $p['servico_percentual'], 2, ',', ''), '0'), ',')) ?>%)</dt><dd><?= $e($r($c['servico'])) ?></dd></div><?php endif; ?>
                    <?php if ($c['desconto'] > 0): ?><div class="conta-desconto"><dt>Desconto<?= $p['motivo_desconto'] ? ' · ' . $e((string) $p['motivo_desconto']) : '' ?></dt><dd>−<?= $e($r($c['desconto'])) ?></dd></div><?php endif; ?>
                    <div class="conta-total"><dt>Total</dt><dd><?= $e($r($c['total'])) ?></dd></div>
                    <?php if ($pessoas > 1 && $c['total'] > 0): ?><div class="conta-divisao"><dt>Dividido por <?= $pessoas ?></dt><dd><?= $e($r(intdiv($c['total'] + $pessoas - 1, $pessoas))) ?> cada</dd></div><?php endif; ?>
                    <?php if ($c['pago'] > 0): ?>
                        <div><dt>Já pago</dt><dd><?= $e($r($c['pago'])) ?></dd></div>
                        <?php if ($c['aberta']): ?><div class="conta-falta"><dt>Falta pagar</dt><dd><?= $e($r($c['restante'])) ?></dd></div><?php endif; ?>
                    <?php endif; ?>
                </dl>

                <?php if ($c['aberta']): ?>
                    <a class="btn btn-primary btn-lg btn-block conta-receber<?= $c['total'] > 0 ? '' : ' is-desligado' ?>" href="<?= $e($url . '/pagar') ?>"<?= $c['total'] > 0 ? '' : ' aria-disabled="true" tabindex="-1"' ?>>
                        <?= Icon::svg('wallet', 'icon-sm') ?>Receber e fechar conta
                    </a>
                    <?php if (!$caixaAberto): ?><p class="conta-aviso"><?= Icon::svg('lock', 'icon-sm') ?>O caixa está fechado. <a href="<?= $e($base) ?>">Abra o caixa</a> para receber.</p><?php endif; ?>

                    <details class="conta-mais"<?= isset($erros['desconto']) || isset($erros['motivo_desconto']) || isset($erros['pessoas']) ? ' open' : '' ?>>
                        <summary><?= Icon::svg('percent', 'icon-sm') ?>Serviço, desconto e dividir a conta</summary>
                        <form method="post" action="<?= $e($url . '/ajustes') ?>" class="conta-ajustes" novalidate data-ajax>
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <label class="alternador"><span><strong>Taxa de serviço (<?= $e(rtrim(rtrim(number_format((float) ComandaService::SERVICO_PADRAO, 2, ',', ''), '0'), ',')) ?>%)</strong><small>Os 10% do garçom.</small></span>
                                <input type="checkbox" name="servico" value="1"<?= $servicoLigado ? ' checked' : '' ?>><span class="alternador-chave" aria-hidden="true"></span></label>
                            <div class="campos-linha">
                                <label class="campo-caixa"><span>Desconto (R$)</span><input name="desconto" inputmode="numeric" data-mask="money" placeholder="0,00" value="<?= $e($enviado['desconto'] ?? ($c['desconto'] > 0 ? Numero::dinheiroCampo((string) $p['desconto']) : '')) ?>"<?= isset($erros['desconto']) ? ' aria-invalid="true"' : '' ?>></label>
                                <label class="campo-caixa"><span>Dividir por</span><input name="pessoas" inputmode="numeric" maxlength="2" placeholder="pessoas" value="<?= $e($enviado['pessoas'] ?? ($pessoas > 0 ? (string) $pessoas : '')) ?>"<?= isset($erros['pessoas']) ? ' aria-invalid="true"' : '' ?>></label>
                            </div>
                            <label class="campo-caixa"><span>Motivo do desconto</span><input name="motivo_desconto" maxlength="140" placeholder="Ex.: aniversário, demora na cozinha" value="<?= $e($enviado['motivo_desconto'] ?? (string) ($p['motivo_desconto'] ?? '')) ?>"<?= isset($erros['motivo_desconto']) ? ' aria-invalid="true"' : '' ?>></label>
                            <?php foreach (['desconto', 'motivo_desconto', 'pessoas'] as $campo): if (isset($erros[$campo])): ?><p class="campo-erro"><?= $e($erros[$campo]) ?></p><?php endif; endforeach; ?>
                            <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Aplicar</button>
                        </form>
                    </details>

                    <?php if ($livres !== []): ?>
                        <details class="conta-mais">
                            <summary><?= Icon::svg('move', 'icon-sm') ?><?= $p['tipo'] === 'mesa' ? 'Trocar de mesa' : 'Levar para uma mesa' ?></summary>
                            <form method="post" action="<?= $e($url . '/transferir') ?>" class="conta-ajustes">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <label class="campo-caixa"><span>Para qual mesa?</span>
                                    <select name="mesa" required>
                                        <?php foreach ($livres as $m): ?><option value="<?= (int) $m['id'] ?>">Mesa <?= (int) $m['numero'] ?><?= $m['apelido'] ? ' · ' . $e((string) $m['apelido']) : '' ?><?= (int) $m['comandas'] > 0 ? ' (ocupada: entra como mais uma comanda)' : ' (livre)' ?></option><?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('move', 'icon-sm') ?>Levar a conta</button>
                            </form>
                        </details>
                    <?php endif; ?>

                    <?php if ($c['pago'] === 0): ?>
                        <details class="conta-mais conta-perigo">
                            <summary><?= Icon::svg('circle-x', 'icon-sm') ?>Cancelar comanda</summary>
                            <form method="post" action="<?= $e($url . '/cancelar') ?>" class="conta-ajustes"
                                  data-confirm="<?= count($c['irmas']) > 1 ? 'Os itens voltam para o estoque. As outras comandas da mesa continuam abertas.' : 'Os itens voltam para o estoque e a mesa fica livre.' ?>" data-confirm-titulo="Cancelar <?= $e($c['nome'] !== '' ? 'a comanda de ' . $c['nome'] : 'esta comanda') ?>?" data-confirm-acao="Cancelar comanda" data-confirm-perigo="1">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <label class="campo-caixa"><span>Motivo (opcional)</span><input name="motivo" maxlength="140" placeholder="Ex.: cliente foi embora sem consumir"></label>
                                <button class="btn btn-ghost btn-sm area-remover" type="submit"><?= Icon::svg('circle-x', 'icon-sm') ?>Cancelar comanda</button>
                            </form>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($c['pagamentos'] !== []): ?>
                        <h3 class="conta-subtitulo">Pagamentos</h3>
                        <ul class="caixa-linhas">
                            <?php foreach ($c['pagamentos'] as $pg): ?>
                                <li><span><strong><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?></strong><small><?= $e(date('H:i', (int) strtotime((string) $pg['criado_em']))) ?><?= Numero::centavos((string) $pg['troco']) > 0 ? ' · troco ' . $e(Numero::reais((string) $pg['troco'])) : '' ?></small></span>
                                    <span class="caixa-valor"><?= $e(Numero::reais((string) $pg['valor'])) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($p['status'] === 'cancelado' && $p['motivo_cancelamento']): ?><p class="conta-aviso"><?= Icon::svg('info', 'icon-sm') ?>Motivo: <?= $e((string) $p['motivo_cancelamento']) ?></p><?php endif; ?>
                    <a class="btn btn-primary btn-block" href="<?= $e($base . '/mesas') ?>"><?= Icon::svg('armchair', 'icon-sm') ?>Voltar para as mesas</a>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php if ($c['aberta']): ?>
        <div class="comanda-barra" data-regiao="barra">
            <div><small><?= (int) $qtdItens ?> <?= $qtdItens === 1 ? 'item' : 'itens' ?></small><strong><?= $e($r($c['restante'] > 0 || $c['pago'] > 0 ? $c['restante'] : $c['total'])) ?></strong></div>
            <a class="btn btn-primary<?= $c['total'] > 0 ? '' : ' is-desligado' ?>" href="<?= $e($url . '/pagar') ?>"<?= $c['total'] > 0 ? '' : ' aria-disabled="true" tabindex="-1"' ?>><?= Icon::svg('wallet', 'icon-sm') ?>Receber</a>
        </div>

        <dialog class="folha" data-dialogo-produto aria-labelledby="produto-titulo">
            <form method="post" action="<?= $e($url . '/itens') ?>" class="folha-caixa" data-ajax data-form-produto>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="produto" value="" data-produto-campo>
                <div class="folha-topo">
                    <h2 id="produto-titulo" data-produto-titulo>Produto</h2>
                    <button class="folha-fechar" type="button" aria-label="Fechar" data-fechar-dialogo><?= Icon::svg('x') ?></button>
                </div>
                <div class="qtd" role="group" aria-label="Quantidade">
                    <button type="button" class="qtd-botao" data-qtd="-1" aria-label="Menos um"><?= Icon::svg('minus') ?></button>
                    <input class="qtd-valor" name="quantidade" value="1" inputmode="numeric" maxlength="2" aria-label="Quantidade" data-qtd-campo>
                    <button type="button" class="qtd-botao" data-qtd="1" aria-label="Mais um"><?= Icon::svg('plus') ?></button>
                </div>
                <label class="campo-caixa"><span>Observação (opcional)</span><input name="observacao" maxlength="140" placeholder="Ex.: sem cebola, bem passado" autocomplete="off"></label>
                <button class="btn btn-primary btn-lg btn-block" type="submit" data-produto-lancar>Lançar</button>
            </form>
        </dialog>

        <dialog class="folha" data-dialogo-cancelar aria-labelledby="cancelar-titulo">
            <form method="post" action="" class="folha-caixa" data-ajax data-form-cancelar>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="folha-topo">
                    <h2 id="cancelar-titulo">Cancelar <span data-cancelar-nome></span>?</h2>
                    <button class="folha-fechar" type="button" aria-label="Fechar" data-fechar-dialogo><?= Icon::svg('x') ?></button>
                </div>
                <p class="texto-suave">O item sai da conta e os ingredientes voltam para o estoque.</p>
                <fieldset class="escolhas escolhas-compactas">
                    <legend>Motivo</legend>
                    <?php foreach (['Lançado errado', 'Cliente desistiu', 'Demorou demais', 'Acabou na cozinha'] as $i => $motivo): ?>
                        <label class="escolha"><input type="radio" name="motivo" value="<?= $e($motivo) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= $e($motivo) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
                <button class="btn btn-wine btn-block" type="submit"><?= Icon::svg('x', 'icon-sm') ?>Cancelar item</button>
            </form>
        </dialog>
    <?php endif; ?>

    <div class="impressao" aria-hidden="true">
        <p class="impressao-titulo"><?= $e((string) $restaurante['nome']) ?></p>
        <p><?= $e($c['rotulo']) ?> · Pedido #<?= (int) $p['numero'] ?> · <?= $e(date('d/m/Y H:i')) ?></p>
        <table>
            <?php foreach ($ativos as $i): ?><tr><td><?= (int) $i['quantidade'] ?>x <?= $e((string) $i['nome']) ?></td><td><?= $e(Numero::reais((string) $i['total'])) ?></td></tr><?php endforeach; ?>
            <tr class="impressao-linha"><td>Itens</td><td><?= $e($r($c['subtotal'])) ?></td></tr>
            <?php if ($c['servico'] > 0): ?><tr><td>Serviço</td><td><?= $e($r($c['servico'])) ?></td></tr><?php endif; ?>
            <?php if ($c['desconto'] > 0): ?><tr><td>Desconto</td><td>−<?= $e($r($c['desconto'])) ?></td></tr><?php endif; ?>
            <tr class="impressao-total"><td>Total</td><td><?= $e($r($c['total'])) ?></td></tr>
            <?php if ($pessoas > 1 && $c['total'] > 0): ?><tr><td>Por pessoa (<?= $pessoas ?>)</td><td><?= $e($r(intdiv($c['total'] + $pessoas - 1, $pessoas))) ?></td></tr><?php endif; ?>
        </table>
        <p>Não é documento fiscal.</p>
    </div>
</div>
