<?php
/** Detalhe de uma comanda (aberta, fechada ou cancelada). Espera: $d (HistoricoComandas::detalhe), $base. */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$quando = static fn (?string $v): string => $v ? date('d/m/Y \à\s H:i', (int) strtotime($v)) : '—';
$p = $d['pedido'];
[$classe, $icone, $texto] = match ($p['status']) {
    'aberto' => ['etiqueta-atencao', 'lock-open', 'Aberta'],
    'concluido' => ['etiqueta-ok', 'circle-check', 'Fechada e paga'],
    'cancelado' => ['etiqueta-neutra', 'circle-x', 'Cancelada'],
    default => ['etiqueta-neutra', 'minus', (string) $p['status']],
};
$fim = $d['fechamento'];
$ativos = array_values(array_filter($d['itens'], static fn (array $i): bool => $i['cancelado_em'] === null));
$cancelados = array_values(array_filter($d['itens'], static fn (array $i): bool => $i['cancelado_em'] !== null));
$qtd = static fn (array $i): string => QuantidadeVenda::textoDoBanco((string) $i['quantidade'], (string) ($i['unidade'] ?? 'un'));
if (!empty($notaFiscal)) {
    $pageStyles = [...($pageStyles ?? []), 'css/caixa-fiscal.css'];
    $pageScripts = [...($pageScripts ?? []), 'js/caixa-fiscal.js'];
}
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1><?= $e((string) $d['rotulo']) ?></h1>
            <p>Pedido #<?= \Cardapim\Services\Pedidos\NumeroDoDia::de($p) ?> · <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e($texto) ?></span></p>
        </div>
        <div class="painel-acoes">
            <?php if ($d['aberta']): ?><a class="btn btn-primary btn-sm" href="<?= $e($base . '/comandas/' . (int) $p['id']) ?>"><?= Icon::svg('receipt', 'icon-sm') ?>Abrir comanda</a><?php endif; ?>
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/comandas') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Comandas</a>
        </div>
    </header>
    <?php $idPedido = (int) $d['pedido']['id']; $voltar = 'historico'; require __DIR__ . '/_nota-fiscal.php'; ?>

    <section class="bloco" aria-labelledby="titulo-quem">
        <div class="bloco-cabecalho"><h2 id="titulo-quem"><?= Icon::svg('clock') ?>Quem e quando</h2></div>
        <dl class="caixa-formas comanda-quem">
            <div class="caixa-forma"><dt>Aberta</dt><dd><?= $e($quando((string) $p['criado_em'])) ?><?= $d['aberta_por'] ? ' · ' . $e((string) $d['aberta_por']) : '' ?></dd></div>
            <?php if ($p['status'] === 'concluido'): ?>
                <div class="caixa-forma"><dt>Fechada</dt><dd><?= $e($quando($p['concluido_em'])) ?><?= !empty($fim['autor']) ? ' · ' . $e((string) $fim['autor']) : '' ?></dd></div>
            <?php elseif ($p['status'] === 'cancelado'): ?>
                <div class="caixa-forma"><dt>Cancelada</dt><dd><?= $e($quando($p['cancelado_em'])) ?><?= !empty($fim['autor']) ? ' · ' . $e((string) $fim['autor']) : '' ?></dd></div>
                <?php if (!empty($p['motivo_cancelamento'])): ?><div class="caixa-forma"><dt>Motivo</dt><dd><?= $e((string) $p['motivo_cancelamento']) ?></dd></div><?php endif; ?>
            <?php else: ?>
                <div class="caixa-forma"><dt>Situação</dt><dd>Ainda aberta</dd></div>
            <?php endif; ?>
            <?php if ((int) ($p['pessoas'] ?? 0) > 0): ?><div class="caixa-forma"><dt>Pessoas</dt><dd><?= (int) $p['pessoas'] ?></dd></div><?php endif; ?>
        </dl>
    </section>

    <div class="caixa-colunas">
        <section class="bloco" aria-labelledby="titulo-itens">
            <div class="bloco-cabecalho"><h2 id="titulo-itens"><?= Icon::svg('utensils') ?>Itens (<?= count($ativos) ?>)</h2></div>
            <?php if ($ativos === []): ?><p class="texto-suave">Nenhum item.</p><?php else: ?>
                <ul class="caixa-linhas">
                    <?php foreach ($ativos as $i): ?>
                        <li><span><strong><?= $e($qtd($i) . ' ' . $i['nome']) ?></strong><small><?= !empty($i['complementos_texto']) ? $e((string) $i['complementos_texto']) . ' · ' : '' ?><?= $e(date('H:i', (int) strtotime((string) $i['criado_em']))) ?><?= $i['autor'] ? ' · ' . $e((string) $i['autor']) : '' ?></small></span><strong><?= $e(Numero::reais((string) $i['total'])) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <dl class="caixa-formas">
                <div class="caixa-forma"><dt>Itens</dt><dd><?= $e($r($d['subtotal'])) ?></dd></div>
                <?php if ($d['servico'] > 0): ?><div class="caixa-forma"><dt>Serviço</dt><dd><?= $e($r($d['servico'])) ?></dd></div><?php endif; ?>
                <?php if ($d['desconto'] > 0): ?><div class="caixa-forma"><dt>Desconto<?= !empty($p['motivo_desconto']) ? ' (' . $e((string) $p['motivo_desconto']) . ')' : '' ?></dt><dd class="valor-menos">−<?= $e($r($d['desconto'])) ?></dd></div><?php endif; ?>
                <?php if (($d['taxa_pagamento'] ?? 0) > 0): ?><div class="caixa-forma"><dt><?= $e((string) ($d['taxa_pagamento_rotulo'] ?: 'Taxas de pagamento')) ?></dt><dd><?= $e($r((int) $d['taxa_pagamento'])) ?></dd></div><?php endif; ?>
                <div class="caixa-forma caixa-forma-total"><dt>Total</dt><dd><?= $e($r($d['total'])) ?></dd></div>
            </dl>
        </section>

        <section class="bloco" aria-labelledby="titulo-pagamentos">
            <div class="bloco-cabecalho"><h2 id="titulo-pagamentos"><?= Icon::svg('wallet') ?>Pagamentos</h2></div>
            <?php if ($d['pagamentos'] === []): ?>
                <p class="texto-suave"><?= $p['status'] === 'cancelado' ? 'Cancelada sem pagamento.' : 'Nenhum pagamento ainda.' ?></p>
            <?php else: ?>
                <ul class="caixa-linhas">
                    <?php foreach ($d['pagamentos'] as $pg): ?>
                        <li><span><strong><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?></strong><small><?= $e(date('d/m H:i', (int) strtotime((string) $pg['criado_em']))) ?><?= $pg['autor'] ? ' · ' . $e((string) $pg['autor']) : '' ?><?= Numero::centavos((string) ($pg['taxa'] ?? '0')) > 0 ? ' · inclui taxa de ' . $e(Numero::reais((string) $pg['taxa'])) : '' ?><?= Numero::centavos((string) $pg['troco']) > 0 ? ' · troco ' . $e(Numero::reais((string) $pg['troco'])) : '' ?></small></span><strong><?= $e(Numero::reais((string) $pg['valor'])) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <dl class="caixa-formas">
                <div class="caixa-forma"><dt>Pago</dt><dd><?= $e($r($d['pago'])) ?></dd></div>
                <?php if ($d['restante'] > 0 && $p['status'] !== 'cancelado'): ?><div class="caixa-forma caixa-forma-total"><dt>Falta pagar</dt><dd><?= $e($r($d['restante'])) ?></dd></div><?php endif; ?>
            </dl>
        </section>
    </div>

    <?php if ($cancelados !== []): ?>
        <section class="bloco" aria-labelledby="titulo-cancelados">
            <div class="bloco-cabecalho"><h2 id="titulo-cancelados"><?= Icon::svg('circle-x') ?>Itens cancelados (<?= count($cancelados) ?>)</h2></div>
            <ul class="caixa-linhas">
                <?php foreach ($cancelados as $i): ?>
                    <li><span><strong><?= $e($qtd($i) . ' ' . $i['nome']) ?></strong><small><?= $e(date('d/m H:i', (int) strtotime((string) $i['cancelado_em']))) ?><?= isset($d['cancelados_por'][(int) $i['id']]) ? ' · ' . $e($d['cancelados_por'][(int) $i['id']]) : '' ?><?= !empty($i['motivo_cancelamento']) ? ' · ' . $e((string) $i['motivo_cancelamento']) : '' ?></small></span><span class="texto-suave"><?= $e(Numero::reais((string) $i['total'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="bloco" aria-labelledby="titulo-historico">
        <div class="bloco-cabecalho"><h2 id="titulo-historico"><?= Icon::svg('rotate-ccw-clock') ?>Histórico</h2></div>
        <?php if ($d['eventos'] === []): ?><p class="texto-suave">Sem registros.</p><?php else: ?>
            <ul class="caixa-linhas">
                <?php foreach ($d['eventos'] as $ev): ?>
                    <li><span><strong><?= $e((string) $ev['descricao']) ?></strong><small><?= $e(date('d/m H:i', (int) strtotime((string) $ev['criado_em']))) ?><?= $ev['autor'] ? ' · ' . $e((string) $ev['autor']) : '' ?></small></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
