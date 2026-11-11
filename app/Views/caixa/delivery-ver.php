<?php
/**
 * Pedido do delivery no caixa: o que fazer agora (aceitar, pronto, despachar para o motoqueiro, entregue) e os dados.
 * Espera: $d (DeliveryService::detalhes), $entregadores, $caixaAberto, $gestor, $base, $csrf, $restaurante.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Pedidos\StatusPedido;
use Cardapim\Shared\Numero;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$p = $d['pedido'];
$url = $base . '/delivery/' . (int) $p['id'];
$status = (string) $p['status'];
$entrega = $p['tipo'] === 'entrega';
$ativo = !in_array($status, ['concluido', 'cancelado'], true);
$total = Numero::centavos((string) $p['total']);
$troco = $p['forma_pagamento'] === 'dinheiro' && $p['troco_para'] !== null ? Numero::centavos((string) $p['troco_para']) - $total : 0;
$endereco = trim(implode(', ', array_filter([(string) $p['entrega_logradouro'] . ($p['entrega_numero'] ? ', ' . $p['entrega_numero'] : ''), (string) ($p['entrega_complemento'] ?? ''), (string) ($p['entrega_bairro'] ?? ''), (string) ($p['entrega_cidade'] ?? '')])));
$mapa = $p['entrega_latitude'] !== null
    ? 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $p['entrega_latitude']) . '&mlon=' . rawurlencode((string) $p['entrega_longitude']) . '#map=18/' . $p['entrega_latitude'] . '/' . $p['entrega_longitude']
    : 'https://www.openstreetmap.org/search?query=' . rawurlencode($endereco);
$etapas = $entrega ? ['recebido' => 'Recebido', 'em_preparo' => 'Em preparo', 'pronto' => 'Pronto', 'saiu_entrega' => 'Saiu', 'concluido' => 'Entregue']
    : ['recebido' => 'Recebido', 'em_preparo' => 'Em preparo', 'pronto' => 'Pronto', 'concluido' => 'Retirado'];
$ordem = array_keys($etapas);
$posicao = array_search($status, $ordem, true);
$minutos = max(0, (int) floor((time() - (int) strtotime((string) $p['criado_em'])) / 60));
?>
<div class="painel-page delivery-pedido" data-regiao="pagina-pedido">
    <header class="painel-cabecalho">
        <div>
            <h1>Pedido #<?= (int) $p['numero'] ?></h1>
            <p><?= $entrega ? 'Entrega' : 'Retirada no local' ?> · feito às <?= $e(date('H:i', (int) strtotime((string) $p['criado_em']))) ?><?= $ativo ? ' (' . ($minutos < 60 ? $minutos . ' min' : intdiv($minutos, 60) . 'h' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT)) . ')' : '' ?></p>
        </div>
        <div class="painel-acoes">
            <button class="btn btn-outline btn-sm" type="button" data-imprimir><?= Icon::svg('printer', 'icon-sm') ?>Imprimir</button>
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/delivery') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Delivery</a>
        </div>
    </header>

    <?php if ($status !== 'cancelado' && $status !== 'aguardando_pagamento'): ?>
        <ol class="etapas" aria-label="Situação do pedido">
            <?php foreach ($etapas as $chave => $rotulo): $i = array_search($chave, $ordem, true); ?>
                <li class="<?= $posicao !== false && $i < $posicao ? 'is-feito' : ($i === $posicao ? 'is-agora' : '') ?>"<?= $i === $posicao ? ' aria-current="step"' : '' ?>><span><?= $e($rotulo) ?></span></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <div class="delivery-colunas-detalhe">
        <section class="bloco delivery-acao" aria-labelledby="titulo-agora">
            <h2 id="titulo-agora" class="sr-only">O que fazer agora</h2>
            <?php if ($status === 'aguardando_pagamento'): ?>
                <div class="acao-info"><?= Icon::svg('clock', 'icon-lg') ?><div><strong>Esperando o cliente pagar o Pix</strong><p>Quando o pagamento cair, o pedido vira "Novo" sozinho.</p></div></div>
            <?php elseif ($status === 'cancelado'): ?>
                <div class="acao-info is-cancelado"><?= Icon::svg('circle-x', 'icon-lg') ?><div><strong>Pedido cancelado</strong><p><?= $e((string) ($p['motivo_cancelamento'] ?? '')) ?></p></div></div>
            <?php elseif ($status === 'concluido'): ?>
                <div class="acao-info is-ok"><?= Icon::svg('circle-check', 'icon-lg') ?><div><strong><?= $entrega ? 'Entregue' : 'Retirado' ?> às <?= $e(date('H:i', (int) strtotime((string) $p['concluido_em']))) ?></strong><p><?= $d['entregador'] ? 'Levado por ' . $e($d['entregador']) . '. ' : '' ?>Pagamento registrado no caixa.</p></div></div>
            <?php else: ?>
                <?php if ($status === 'recebido'): ?>
                    <form method="post" action="<?= $e($url . '/aceitar') ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-primary btn-lg btn-block" type="submit"><?= Icon::svg('chef-hat', 'icon-sm') ?>Aceitar e preparar</button></form>
                <?php endif; ?>
                <?php if (in_array($status, ['recebido', 'em_preparo'], true)): ?>
                    <form method="post" action="<?= $e($url . '/pronto') ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn <?= $status === 'em_preparo' ? 'btn-primary btn-lg' : 'btn-outline' ?> btn-block" type="submit"><?= Icon::svg('package-check', 'icon-sm') ?>Pedido pronto</button></form>
                <?php endif; ?>

                <?php if ($entrega && in_array($status, ['recebido', 'em_preparo', 'pronto'], true)): ?>
                    <form method="post" action="<?= $e($url . '/despachar') ?>" class="despachar">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <fieldset>
                            <legend><?= Icon::svg('bike', 'icon-sm') ?>Quem vai levar?</legend>
                            <?php if ($entregadores === []): ?>
                                <p class="texto-suave">Nenhum entregador cadastrado.<?= $gestor ? ' <a href="/' . $e(rawurlencode((string) $restaurante['slug'])) . '/gerencia/equipe/nova?funcao=motoqueiro">Cadastrar entregador</a>' : ' Peça ao gerente para cadastrar na Equipe.' ?></p>
                            <?php else: ?>
                                <div class="entregadores">
                                    <?php foreach ($entregadores as $i => $ent): ?>
                                        <label class="entregador"><input type="radio" name="entregador" value="<?= (int) $ent['id'] ?>"<?= count($entregadores) === 1 ? ' checked' : '' ?> required>
                                            <span><strong><?= $e($ent['nome']) ?></strong><small><?= $ent['em_rota'] > 0 ? $ent['em_rota'] . ' em rota agora' : 'Livre' ?></small></span></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </fieldset>
                        <?php if ($entregadores !== []): ?><button class="btn <?= $status === 'pronto' ? 'btn-primary btn-lg' : 'btn-outline' ?> btn-block" type="submit"><?= Icon::svg('send', 'icon-sm') ?>Despachar</button><?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if (($entrega && $status === 'saiu_entrega') || (!$entrega && in_array($status, ['pronto', 'em_preparo', 'recebido'], true))): ?>
                    <?php if ($status === 'saiu_entrega' && $d['entregador']): ?><p class="acao-rota"><?= Icon::svg('bike', 'icon-sm') ?>Saiu com <strong><?= $e($d['entregador']) ?></strong> às <?= $e(date('H:i', (int) strtotime((string) $p['despachado_em']))) ?>.</p>
                        <?php $pos = $d['posicao'] ?? null; $idade = $pos ? time() - (int) strtotime((string) $pos['atualizado_em']) : null; ?>
                        <p class="acao-local <?= $pos && $idade <= 300 ? 'is-ao-vivo' : '' ?>"><?= Icon::svg('map-pin', 'icon-sm') ?>
                            <?php if ($pos && $idade <= 300): ?>Localização ao vivo (há <?= $idade < 60 ? $idade . ' s' : intdiv($idade, 60) . ' min' ?>). <a href="https://www.openstreetmap.org/?mlat=<?= $e((string) $pos['latitude']) ?>&amp;mlon=<?= $e((string) $pos['longitude']) ?>#map=17/<?= $e((string) $pos['latitude']) ?>/<?= $e((string) $pos['longitude']) ?>" target="_blank" rel="noopener">Ver no mapa</a>
                            <?php else: ?>O entregador não está compartilhando a localização.<?php endif; ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?= $e($url . '/concluir') ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn <?= !$entrega && $status !== 'pronto' ? 'btn-outline' : 'btn-primary btn-lg' ?> btn-block" type="submit"<?= !$caixaAberto && $p['pagamento_status'] !== 'pago' ? ' disabled' : '' ?>>
                            <?= Icon::svg('circle-check', 'icon-sm') ?><?= $entrega ? 'Entregue e recebido' : 'Cliente retirou' ?>
                        </button></form>
                    <?php if (!$caixaAberto && $p['pagamento_status'] !== 'pago'): ?><p class="conta-aviso"><?= Icon::svg('lock', 'icon-sm') ?>Abra o caixa para receber este pedido. <a href="<?= $e($base) ?>">Abrir caixa</a></p><?php endif; ?>
                <?php endif; ?>

                <details class="conta-mais conta-perigo">
                    <summary><?= Icon::svg('circle-x', 'icon-sm') ?>Cancelar pedido</summary>
                    <form method="post" action="<?= $e($url . '/cancelar') ?>" class="conta-ajustes"
                          data-confirm="O cliente vê o cancelamento e o estoque volta." data-confirm-titulo="Cancelar o pedido #<?= (int) $p['numero'] ?>?" data-confirm-acao="Cancelar pedido" data-confirm-perigo="1">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label class="campo-caixa"><span>Motivo (o cliente vê)</span><input name="motivo" required minlength="3" maxlength="140" placeholder="Ex.: acabou o produto, endereço fora da área"></label>
                        <button class="btn btn-ghost btn-sm area-remover" type="submit"><?= Icon::svg('circle-x', 'icon-sm') ?>Cancelar pedido</button>
                    </form>
                </details>
            <?php endif; ?>

            <div class="pagamento-resumo">
                <h3><?= Icon::svg('wallet', 'icon-sm') ?>Pagamento</h3>
                <p><strong><?= $e(StatusPedido::forma((string) $p['forma_pagamento'], (string) $p['tipo'])) ?></strong>
                    <?php if ($p['pagamento_status'] === 'pago'): ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Pago</span><?php endif; ?></p>
                <p class="pagamento-total"><?= $e(Numero::reais((string) $p['total'])) ?></p>
                <?php if ($troco > 0): ?><p class="pagamento-troco"><?= Icon::svg('banknote', 'icon-sm') ?>Troco para <?= $e(Numero::reais((string) $p['troco_para'])) ?>: levar <strong><?= $e(Numero::reais(\Cardapim\Services\Caixa\CaixaService::dec($troco))) ?></strong></p><?php endif; ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-dados">
            <h2 class="sr-only" id="titulo-dados">Dados do pedido</h2>
            <div class="pedido-cliente">
                <h3><?= Icon::svg('user', 'icon-sm') ?><?= $e((string) ($p['cliente_nome'] ?? 'Cliente')) ?></h3>
                <?php if ($p['cliente_whatsapp']): ?>
                    <a class="link-acao" href="https://wa.me/55<?= $e((string) $p['cliente_whatsapp']) ?>" target="_blank" rel="noopener"><?= Icon::svg('phone', 'icon-sm') ?><?= $e(Documento::formatarTelefone((string) $p['cliente_whatsapp'])) ?></a>
                <?php endif; ?>
            </div>
            <?php if ($entrega): ?>
                <div class="pedido-endereco">
                    <h3><?= Icon::svg('map-pin', 'icon-sm') ?>Entregar em</h3>
                    <p><?= $e($endereco) ?></p>
                    <?php if ($p['entrega_referencia']): ?><p class="texto-suave">Referência: <?= $e((string) $p['entrega_referencia']) ?></p><?php endif; ?>
                    <p class="texto-suave"><?= $e((string) ($p['area_descricao'] ?? '')) ?><?= $p['entrega_distancia_km'] !== null ? ' · ' . $e(str_replace('.', ',', (string) (float) $p['entrega_distancia_km'])) . ' km' : '' ?></p>
                    <a class="link-acao" href="<?= $e($mapa) ?>" target="_blank" rel="noopener"><?= Icon::svg('map', 'icon-sm') ?>Ver no mapa</a>
                </div>
            <?php endif; ?>
            <h3 class="pedido-subtitulo"><?= Icon::svg('receipt', 'icon-sm') ?>Itens</h3>
            <ul class="conta-itens">
                <?php foreach ($d['itens'] as $i): ?>
                    <li><span class="conta-qtd"><?= (int) $i['quantidade'] ?>x</span><span class="conta-nome"><?= $e((string) $i['nome']) ?><?php if ($i['observacao']): ?><small><?= $e((string) $i['observacao']) ?></small><?php endif; ?></span><span class="conta-preco"><?= $e(Numero::reais((string) $i['total'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <dl class="conta-totais">
                <div><dt>Itens</dt><dd><?= $e(Numero::reais((string) $p['subtotal'])) ?></dd></div>
                <?php if ($entrega): ?><div><dt>Taxa de entrega</dt><dd><?= Numero::centavos((string) $p['taxa_entrega']) === 0 ? 'Grátis' : $e(Numero::reais((string) $p['taxa_entrega'])) ?></dd></div><?php endif; ?>
                <div class="conta-total"><dt>Total</dt><dd><?= $e(Numero::reais((string) $p['total'])) ?></dd></div>
            </dl>
            <?php if ($p['observacao']): ?><p class="pedido-obs"><?= Icon::svg('pencil', 'icon-sm') ?><?= $e((string) $p['observacao']) ?></p><?php endif; ?>
            <details class="conta-mais">
                <summary><?= Icon::svg('rotate-ccw-clock', 'icon-sm') ?>Linha do tempo</summary>
                <ul class="caixa-linhas">
                    <?php foreach ($d['eventos'] as $ev): ?><li><span><?= $e((string) $ev['descricao']) ?></span><span class="texto-suave"><?= $e(date('H:i', (int) strtotime((string) $ev['criado_em']))) ?></span></li><?php endforeach; ?>
                </ul>
            </details>
        </section>
    </div>

    <div class="impressao" aria-hidden="true">
        <p class="impressao-titulo"><?= $e((string) $restaurante['nome']) ?> · Pedido #<?= (int) $p['numero'] ?></p>
        <p><?= $entrega ? 'ENTREGA' : 'RETIRADA' ?> · <?= $e(date('d/m/Y H:i', (int) strtotime((string) $p['criado_em']))) ?></p>
        <p><?= $e((string) ($p['cliente_nome'] ?? '')) ?><?= $p['cliente_whatsapp'] ? ' · ' . $e(Documento::formatarTelefone((string) $p['cliente_whatsapp'])) : '' ?></p>
        <?php if ($entrega): ?><p><?= $e($endereco) ?><?= $p['entrega_referencia'] ? ' (' . $e((string) $p['entrega_referencia']) . ')' : '' ?></p><?php endif; ?>
        <table>
            <?php foreach ($d['itens'] as $i): ?><tr><td><?= (int) $i['quantidade'] ?>x <?= $e((string) $i['nome']) ?><?= $i['observacao'] ? ' (' . $e((string) $i['observacao']) . ')' : '' ?></td><td><?= $e(Numero::reais((string) $i['total'])) ?></td></tr><?php endforeach; ?>
            <?php if ($entrega): ?><tr><td>Entrega</td><td><?= $e(Numero::reais((string) $p['taxa_entrega'])) ?></td></tr><?php endif; ?>
            <tr class="impressao-total"><td>Total</td><td><?= $e(Numero::reais((string) $p['total'])) ?></td></tr>
        </table>
        <p><?= $e(StatusPedido::forma((string) $p['forma_pagamento'], (string) $p['tipo'])) ?><?= $p['pagamento_status'] === 'pago' ? ' (PAGO)' : '' ?><?= $troco > 0 ? ' · troco para ' . $e(Numero::reais((string) $p['troco_para'])) : '' ?></p>
        <?php if ($p['observacao']): ?><p>Obs.: <?= $e((string) $p['observacao']) ?></p><?php endif; ?>
    </div>
</div>
