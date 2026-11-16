<?php
/**
 * Demonstracao local da interface. Nao acessa banco, nao gera pedidos e nao escreve na API.
 * A animacao e controlada exclusivamente pelo JS externo da landing.
 */
use Cardapim\Core\Icon;
?>
<div class="hero-demo" data-hero-demo aria-label="Painel interativo do Deliveryzim">
    <aside class="hero-demo-side" aria-label="Telas do painel">
        <span class="hero-demo-mark" aria-hidden="true"><?= Icon::svg('chef-hat') ?></span>
        <button type="button" class="hero-demo-nav is-active" data-demo-view="overview" aria-label="Ver visão geral" aria-pressed="true"><?= Icon::svg('layout-dashboard') ?><span>Visão geral</span></button>
        <button type="button" class="hero-demo-nav" data-demo-view="orders" aria-label="Ver pedidos" aria-pressed="false"><?= Icon::svg('receipt') ?><span>Pedidos</span></button>
        <button type="button" class="hero-demo-nav" data-demo-view="cash" aria-label="Ver caixa" aria-pressed="false"><?= Icon::svg('wallet') ?><span>Caixa</span></button>
    </aside>
    <div class="hero-demo-workspace">
        <div class="hero-demo-topbar">
            <span>Restaurante Modelo <span class="hero-demo-separator" aria-hidden="true">/</span> <strong data-demo-breadcrumb>Visão geral</strong></span>
            <span class="hero-demo-label" data-demo-activity>248 pedidos hoje</span>
        </div>
        <div class="hero-demo-content">
            <div class="hero-demo-screen" data-demo-screen="overview">
                <div class="hero-demo-heading">
                    <div><span class="hero-demo-eyebrow">Bom dia, gerente!</span><h2>Visão geral</h2></div>
                    <span class="hero-demo-subtitle">Hoje, sua operação em um só lugar</span>
                </div>
                <div class="hero-demo-metrics">
                    <div class="hero-demo-metric"><span><?= Icon::svg('receipt') ?> Pedidos hoje</span><strong data-demo-count>248</strong><small>Pedidos recebidos</small></div>
                    <div class="hero-demo-metric"><span><?= Icon::svg('wallet') ?> Vendas hoje</span><strong data-demo-total>R$ 18.475,90</strong><small>Faturamento do dia</small></div>
                    <div class="hero-demo-metric"><span><?= Icon::svg('chef-hat') ?> Em preparo</span><strong data-demo-preparing>12</strong><small>Pedidos em andamento</small></div>
                </div>
                <div class="hero-demo-card">
                    <div class="hero-demo-card-head"><h3>Pedidos em andamento</h3><button type="button" data-demo-view="orders">Ver pedidos <?= Icon::svg('arrow-right') ?></button></div>
                    <p class="hero-demo-empty" data-demo-empty hidden>Os próximos pedidos aparecem aqui.</p>
                    <div class="hero-demo-order-row" data-demo-order-row>
                        <span class="hero-demo-order-number" data-demo-order-number>#1043</span>
                        <span class="hero-demo-order-detail"><strong>Delivery · Cliente</strong><small>1 × X-Burguer · Entrega</small></span>
                        <span class="hero-demo-status" data-demo-overview-status>Novo</span>
                    </div>
                </div>
            </div>
            <div class="hero-demo-screen" data-demo-screen="orders" hidden>
                <div class="hero-demo-heading"><div><span class="hero-demo-eyebrow">Atendimento</span><h2>Pedidos</h2></div><span class="hero-demo-subtitle">Acompanhe cada etapa</span></div>
                <div class="hero-demo-order-empty" data-demo-orders-empty>
                    <p>Nenhum pedido novo por enquanto.</p>
                    <button type="button" class="hero-demo-primary" data-demo-action="new"><?= Icon::svg('plus') ?> Novo pedido</button>
                </div>
                <div class="hero-demo-card hero-demo-detail" data-demo-orders-detail hidden>
                    <div class="hero-demo-card-head"><h3>Pedido <span data-demo-order-number>#1043</span></h3><span class="hero-demo-status" data-demo-order-status>Novo</span></div>
                    <p class="hero-demo-detail-sub">Entrega · Cliente</p>
                    <div class="hero-demo-product"><span>1 × X-Burguer</span><strong>R$ 24,90</strong></div>
                    <div class="hero-demo-product"><span>Taxa de entrega</span><strong>R$ 7,00</strong></div>
                    <button type="button" class="hero-demo-primary" data-demo-action="receive"><?= Icon::svg('check') ?> <span data-demo-receive-text>Aceitar e preparar</span></button>
                    <p class="hero-demo-done" data-demo-accepted hidden>Pedido aceito, em preparo.</p>
                </div>
            </div>
            <div class="hero-demo-screen" data-demo-screen="cash" hidden>
                <div class="hero-demo-heading"><div><span class="hero-demo-eyebrow">Operação</span><h2>Caixa</h2></div><span class="hero-demo-subtitle" data-demo-cash-label>Caixa fechado</span></div>
                <div class="hero-demo-card hero-demo-cash-card">
                    <div class="hero-demo-cash-summary">
                        <div><span>Vendas neste caixa</span><strong data-demo-cash-total>R$ 0,00</strong></div>
                        <div><span>Produtos adicionados</span><strong data-demo-items>0</strong></div>
                    </div>
                    <button type="button" class="hero-demo-primary" data-demo-action="open"><?= Icon::svg('wallet') ?> <span data-demo-open-text>Abrir caixa</span></button>
                    <div class="hero-demo-add" data-demo-add-box hidden>
                        <p>Adicionar produto ao balcão</p>
                        <div class="hero-demo-product"><span>Refrigerante lata 350 ml</span><strong>R$ 6,00</strong></div>
                        <button type="button" class="hero-demo-secondary" data-demo-action="add"><?= Icon::svg('plus') ?> Adicionar produto</button>
                    </div>
                </div>
            </div>
        </div>
        <p class="sr-only" data-demo-announcement role="status" aria-live="polite"></p>
    </div>
</div>