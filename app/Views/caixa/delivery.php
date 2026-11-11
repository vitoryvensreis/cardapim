<?php
/** Pedidos do cardapio online por etapa (do mais antigo para o mais novo). Espera: $fila, $recentes, $base. */
use Cardapim\Core\Icon;
use Cardapim\Services\Pedidos\StatusPedido;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$colunas = [
    'recebido' => ['Novos', 'bell', 'Aceite para começar a preparar.'],
    'em_preparo' => ['Em preparo', 'chef-hat', ''],
    'pronto' => ['Prontos', 'package-check', 'Despache ou entregue no balcão.'],
    'saiu_entrega' => ['Saíram para entrega', 'bike', ''],
    'aguardando_pagamento' => ['Esperando o Pix', 'clock', 'Aparecem como novos quando o cliente pagar.'],
];
$porStatus = array_fill_keys(array_keys($colunas), []);
foreach ($fila as $p) {
    $porStatus[$p['status']][] = $p;
}
$tempo = static function (string $desde): string {
    $m = max(0, (int) floor((time() - (int) strtotime($desde)) / 60));
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
?>
<div class="painel-page" data-regiao="pagina-delivery">
    <header class="painel-cabecalho">
        <div>
            <h1>Delivery</h1>
            <p>Pedidos do cardápio online em ordem de chegada. Toque no pedido para ver, preparar e despachar.</p>
        </div>
    </header>

    <?php if ($fila === []): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('bike') ?><strong>Nenhum pedido em andamento</strong><p>Quando um cliente pedir pelo cardápio, o pedido aparece aqui e no menu ao lado, na hora.</p></div></section>
    <?php else: ?>
        <div class="delivery-colunas">
            <?php foreach ($colunas as $status => [$titulo, $icone, $dica]): if ($porStatus[$status] === [] && $status === 'aguardando_pagamento') { continue; } ?>
                <section class="delivery-coluna" aria-labelledby="col-<?= $e($status) ?>">
                    <h2 id="col-<?= $e($status) ?>"><?= Icon::svg($icone, 'icon-sm') ?><?= $e($titulo) ?> <span class="fila-contagem"><?= count($porStatus[$status]) ?></span></h2>
                    <?php if ($porStatus[$status] === []): ?>
                        <p class="texto-suave delivery-vazio">Nenhum.</p>
                    <?php else: ?>
                        <?php if ($dica !== ''): ?><p class="delivery-dica"><?= $e($dica) ?></p><?php endif; ?>
                        <ul class="delivery-cartoes">
                            <?php foreach ($porStatus[$status] as $p): ?>
                                <li><a class="delivery-cartao" href="<?= $e($base . '/delivery/' . (int) $p['id']) ?>">
                                    <span class="delivery-linha"><strong>#<?= (int) $p['numero'] ?></strong><span><?= Icon::svg('clock', 'icon-sm') ?><?= $e($tempo((string) $p['criado_em'])) ?></span></span>
                                    <span class="delivery-linha"><span><?= $e((string) ($p['cliente_nome'] ?? 'Cliente')) ?></span><strong><?= $e(Numero::reais((string) $p['total'])) ?></strong></span>
                                    <span class="delivery-linha delivery-sub">
                                        <span><?= $p['tipo'] === 'entrega' ? Icon::svg('map-pin', 'icon-sm') . $e((string) ($p['entrega_bairro'] ?? 'Entrega')) : Icon::svg('store', 'icon-sm') . 'Retirada' ?></span>
                                        <span><?= $e(StatusPedido::FORMAS[$p['forma_pagamento']] ?? '') ?></span>
                                    </span>
                                    <?php if (trim((string) $p['entregador']) !== ''): ?><span class="delivery-linha delivery-sub"><span><?= Icon::svg('bike', 'icon-sm') ?><?= $e((string) $p['entregador']) ?></span></span><?php endif; ?>
                                </a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($recentes !== []): ?>
        <section class="bloco" aria-labelledby="titulo-recentes">
            <div class="bloco-cabecalho"><h2 id="titulo-recentes"><?= Icon::svg('rotate-ccw-clock') ?>Concluídos e cancelados agora há pouco</h2></div>
            <ul class="caixa-linhas">
                <?php foreach ($recentes as $p): ?>
                    <li><span><a href="<?= $e($base . '/delivery/' . (int) $p['id']) ?>"><strong>#<?= (int) $p['numero'] ?></strong> <?= $e((string) ($p['cliente_nome'] ?? '')) ?></a>
                        <small><?= $p['status'] === 'concluido' ? 'Concluído ' . $e(date('H:i', (int) strtotime((string) $p['concluido_em']))) . (trim((string) $p['entregador']) !== '' ? ' · ' . $e((string) $p['entregador']) : '') : 'Cancelado ' . $e(date('H:i', (int) strtotime((string) $p['cancelado_em']))) ?></small></span>
                        <span class="caixa-valor<?= $p['status'] === 'cancelado' ? ' texto-riscado' : '' ?>"><?= $e(Numero::reais((string) $p['total'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
