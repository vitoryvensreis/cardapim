<?php
use Cardapim\Core\Icon;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$canais = [
    ['utensils-crossed', 'Salão e mesas', false],
    ['store', 'Balcão e retirada', false],
    ['bike', 'Delivery próprio', false],
    ['message-circle', 'WhatsApp', false],
    ['shopping-bag', 'iFood', true],
    ['qr-code', 'Pix Asaas', false],
];

$recursos = [
    ['receipt', 'Pedidos em um lugar', 'Centralize salão, balcão, retirada e delivery em uma única fila, com status claros para toda a equipe.', 'Pedidos'],
    ['clipboard-list', 'Mesas e comandas', 'Veja quais mesas estão ocupadas, lance itens na comanda e feche a conta sem confusão.', 'Salão'],
    ['chef-hat', 'Cozinha no ritmo', 'Pedidos chegam na tela da cozinha em tempo real, por ordem e prioridade. Sem bilhete perdido.', 'Cozinha'],
    ['package', 'Estoque sob controle', 'Insumos, fichas técnicas e movimentações organizados, com aviso quando algo estiver acabando.', 'Estoque'],
    ['wallet', 'Financeiro mais claro', 'Caixa, recebimentos, despesas e vendas do dia em números fáceis de entender.', 'Financeiro'],
    ['plug-zap', 'Conectado de verdade', 'WhatsApp, Pix Asaas e iFood estão no planejamento de integrações do produto.', 'Integrações'],
];

$passos = [
    ['inbox', 'O pedido entra', 'Pela mesa, balcão ou canal digital, todo pedido cai na mesma fila operacional.'],
    ['cooking-pot', 'A cozinha prepara', 'A tela de produção mostra o que preparar primeiro e acompanha cada etapa até ficar pronto.'],
    ['chart-line', 'Você acompanha tudo', 'Caixa, entregas, produtos e resultados reunidos em um painel simples.'],
];

$publicos = [
    ['utensils-crossed', 'Restaurantes', 'Mesas, comandas, cozinha e o movimento de todo dia.'],
    ['hamburger', 'Hamburguerias', 'Adicionais, combos, retirada e delivery sem perder o fio.'],
    ['coffee', 'Cafés e bares', 'Atendimento ágil, produtos organizados e caixa na mão.'],
];

$pedidosMock = [
    ['#1042', 'Mesa 06', '2 itens · Salão', 'utensils-crossed', 'preparing', 'Preparando'],
    ['#1041', 'Delivery', '4 itens · Entrega', 'bike', 'ready', 'Pronto'],
    ['#1040', 'Balcão', '1 item · Retirada', 'store', 'new', 'Novo'],
];
?>
<section class="hero" aria-labelledby="hero-title">
    <div class="hero-bg" aria-hidden="true">
        <span class="hero-blob hero-blob-a"></span>
        <span class="hero-blob hero-blob-b"></span>
        <span class="hero-grid-lines"></span>
    </div>

    <div class="shell hero-layout">
        <div class="hero-copy">
            <h1 id="hero-title" class="hero-title">
                <span class="hero-line">Seu restaurante cresce.</span>
                <em class="hero-line">A bagunça não precisa acompanhar.</em>
            </h1>
            <p class="hero-lead">Pedidos, mesas, caixa, cozinha e delivery no mesmo ritmo. O <?= marca_html() ?> está chegando para deixar a operação mais simples, do primeiro pedido ao último fechamento.</p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="/cadastro">Quero conhecer <?= Icon::svg('arrow-up-right', 'icon-move') ?></a>
                <a class="btn btn-ghost btn-lg" href="#como-funciona">Como funciona <?= Icon::svg('arrow-down', 'icon-move-y') ?></a>
            </div>
            <ul class="hero-points" aria-label="Destaques">
                <li><?= Icon::svg('check') ?>Tudo em um só lugar</li>
                <li><?= Icon::svg('check') ?>Funciona no celular</li>
                <li><?= Icon::svg('check') ?>Pensado para a correria</li>
            </ul>
        </div>

        <div class="hero-visual" data-tilt>
            <div class="hero-visual-inner" data-tilt-inner>
                <div class="dashboard" role="img" aria-label="Prévia ilustrativa do painel do <?= marca_html() ?> com pedidos do dia, vendas e pedidos em andamento">
                    <aside class="dash-side" aria-hidden="true">
                        <span class="dash-logo"><?= Icon::svg('chef-hat') ?></span>
                        <span class="dash-nav is-active"><?= Icon::svg('layout-dashboard') ?></span>
                        <span class="dash-nav"><?= Icon::svg('receipt') ?></span>
                        <span class="dash-nav"><?= Icon::svg('clipboard-list') ?></span>
                        <span class="dash-nav"><?= Icon::svg('chart-no-axes-column') ?></span>
                        <span class="dash-nav dash-nav-bottom"><?= Icon::svg('settings') ?></span>
                    </aside>
                    <div class="dash-main" aria-hidden="true">
                        <div class="dash-top">
                            <div>
                                <span class="dash-overline">Visão geral</span>
                                <strong class="dash-hello">Bom dia, chef!</strong>
                            </div>
                            <span class="dash-bell"><?= Icon::svg('bell') ?><i></i></span>
                        </div>
                        <div class="dash-metrics">
                            <div class="dash-metric">
                                <span><?= Icon::svg('receipt', 'icon-sm') ?>Pedidos hoje</span>
                                <strong><span data-count-to="42">42</span><small class="trend"><?= Icon::svg('trending-up', 'icon-sm') ?>12%</small></strong>
                            </div>
                            <div class="dash-metric">
                                <span><?= Icon::svg('wallet', 'icon-sm') ?>Vendas hoje</span>
                                <strong>R$ <span data-count-to="2.8" data-decimals="1">2,8</span> mil</strong>
                            </div>
                        </div>
                        <div class="dash-orders">
                            <div class="dash-orders-head"><strong>Pedidos em andamento</strong><span>Ver todos <?= Icon::svg('arrow-right', 'icon-sm') ?></span></div>
                            <ul data-live-orders>
                                <?php foreach ($pedidosMock as [$numero, $local, $detalhe, $icone, $status, $rotulo]): ?>
                                    <li class="dash-order">
                                        <span class="dash-order-icon"><?= Icon::svg($icone, 'icon-sm') ?></span>
                                        <span class="dash-order-info"><strong><?= $numero ?> · <?= $local ?></strong><small><?= $detalhe ?></small></span>
                                        <b class="status status-<?= $status ?>" data-status><?= $rotulo ?></b>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="dash-chart">
                            <div class="dash-chart-head"><strong>Movimento da semana</strong><span>Ilustrativo</span></div>
                            <div class="dash-bars">
                                <?php foreach (['S', 'T', 'Q', 'Q', 'S', 'S', 'D'] as $i => $dia): ?>
                                    <span class="dash-bar dash-bar-<?= $i + 1 ?>"><i></i><small><?= $dia ?></small></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="float-card float-order" data-toast aria-hidden="true">
                    <span class="float-icon"><?= Icon::svg('bell') ?></span>
                    <span><small>Pedido novo</small><strong><span data-toast-number>#1043</span> chegou na cozinha</strong></span>
                    <span class="float-check"><?= Icon::svg('check', 'icon-sm') ?></span>
                </div>
                <div class="float-card float-live" aria-hidden="true">
                    <span class="live-dot"></span><strong>Tudo conectado</strong><small>ao vivo</small>
                </div>
            </div>
        </div>
    </div>

    <a class="hero-scroll" href="#canais" aria-label="Rolar para o próximo conteúdo">
        <?= Icon::svg('arrow-down', 'icon-sm') ?>
    </a>
</section>

<section class="channels" id="canais" aria-labelledby="channels-title">
    <div class="shell channels-inner">
        <h2 id="channels-title" class="channels-title">Uma operação,<br>vários canais.</h2>
        <ul class="channels-list">
            <?php foreach ($canais as [$icone, $rotulo, $planejado]): ?>
                <li class="channel<?= $planejado ? ' is-planned' : '' ?>" data-reveal>
                    <span class="channel-icon"><?= Icon::svg($icone) ?></span>
                    <span class="channel-text">
                        <span class="channel-name"><?= $e($rotulo) ?></span>
                        <?php if ($planejado): ?><span class="channel-tag">Em breve</span><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="section features" id="recursos" aria-labelledby="features-title">
    <div class="shell">
        <header class="section-head" data-reveal>
            <h2 id="features-title">Tudo o que você precisa.<br><em>Sem vinte abas abertas.</em></h2>
            <p>Os módulos do <?= marca_html() ?> conversam entre si e acompanham o ritmo real de uma cozinha movimentada.</p>
        </header>
        <div class="features-grid">
            <?php foreach ($recursos as $i => [$icone, $titulo, $texto, $modulo]): ?>
                <article class="feature-card" data-reveal data-spotlight>
                    <span class="feature-icon"><?= Icon::svg($icone) ?></span>
                    <h3><?= $e($titulo) ?></h3>
                    <p><?= $e($texto) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section how" id="como-funciona" aria-labelledby="how-title">
    <div class="shell how-layout">
        <header class="how-intro" data-reveal>
            <h2 id="how-title">Seu pedido faz o caminho.<br><em>Você faz a diferença.</em></h2>
            <p>A equipe inteira olhando para a mesma operação, com cada etapa organizada e visível.</p>
            <a class="text-link" href="/cadastro">Conhecer a plataforma <?= Icon::svg('arrow-up-right', 'icon-sm') ?></a>
        </header>
        <div class="steps" data-steps>
            <span class="steps-track" aria-hidden="true"><span class="steps-progress" data-steps-progress></span></span>
            <ol class="steps-list">
            <?php foreach ($passos as $i => [$icone, $titulo, $texto]): ?>
                <li class="step" data-reveal>
                    <span class="step-icon"><?= Icon::svg($icone) ?></span>
                    <div>
                        <h3><?= $e($titulo) ?></h3>
                        <p><?= $e($texto) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
            </ol>
        </div>
    </div>
</section>

<section class="section audience" id="para-quem" aria-labelledby="audience-title">
    <div class="shell audience-layout">
        <header class="section-head" data-reveal>
            <h2 id="audience-title">Se tem pedido saindo, <em>tem lugar para o <?= marca_html() ?>.</em></h2>
        </header>
        <div class="audience-grid">
            <?php foreach ($publicos as [$icone, $titulo, $texto]): ?>
                <article class="audience-card" data-reveal data-spotlight>
                    <span class="audience-icon"><?= Icon::svg($icone, 'icon-lg') ?></span>
                    <h3><?= $e($titulo) ?></h3>
                    <p><?= $e($texto) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section plans" id="planos" aria-labelledby="plans-title">
    <div class="shell">
        <header class="section-head section-head-center" data-reveal>
            <h2 id="plans-title">Escolha um plano que <em>acompanhe seu negócio.</em></h2>
            <p>Os preços correspondem ao período completo de cada plano.</p>
        </header>

        <?php if ($planos !== []): ?>
            <div class="plans-grid">
                <?php foreach ($planos as $plano): ?>
                    <?php
                    $nome = $e((string) $plano['nome']);
                    $descricao = $e((string) ($plano['descricao'] ?? ''));
                    [$inteiro, $centavos] = explode(',', number_format((float) $plano['valor'], 2, ',', '.'));
                    [$periodo, $periodicidade, $iconePeriodo] = match ($plano['periodo']) {
                        'mensal' => ['Mensal', 'por mês', 'zap'],
                        'semestral' => ['Semestral', 'a cada 6 meses', 'trending-up'],
                        'anual' => ['Anual', 'por ano', 'badge-check'],
                        default => ['Plano', 'por período', 'wallet'],
                    };
                    ?>
                    <article class="plan-card" data-reveal data-spotlight>
                        <span class="plan-period"><?= Icon::svg($iconePeriodo, 'icon-sm') ?><?= $periodo ?></span>
                        <h3><?= $nome ?></h3>
                        <p class="plan-price" aria-label="R$ <?= $inteiro ?>,<?= $centavos ?> <?= $periodicidade ?>">
                            <span class="plan-currency">R$</span><strong><?= $inteiro ?></strong><span class="plan-cents">,<?= $centavos ?></span>
                        </p>
                        <span class="plan-billing"><?= $periodicidade ?></span>
                        <?php if ($descricao !== ''): ?>
                            <p class="plan-description"><?= $descricao ?></p>
                        <?php endif; ?>
                        <a class="btn btn-wine btn-block plan-subscribe" href="/cadastro?plano_id=<?= (int) $plano['id'] ?>" aria-label="Assinar <?= $nome ?>">
                            Assinar <?= Icon::svg('arrow-right', 'icon-move-x') ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="plans-note"><?= Icon::svg('info', 'icon-sm') ?>Os planos apresentados são demonstrativos. O cadastro e a contratação ainda estão em preparação.</p>
        <?php else: ?>
            <div class="plans-empty" role="status">
                <span class="plans-empty-icon"><?= Icon::svg('wallet', 'icon-lg') ?></span>
                <?php if (!empty($planosIndisponiveis)): ?>
                    <h3>Não conseguimos carregar os planos agora.</h3>
                    <p>Recarregue a página em alguns instantes.</p>
                <?php else: ?>
                    <h3>Nenhum plano disponível no momento.</h3>
                    <p>Estamos preparando novas opções de assinatura. Volte em breve.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="final-cta" aria-labelledby="cta-title">
    <div class="shell">
        <div class="cta-panel" data-reveal>
            <span class="cta-spark cta-spark-a" aria-hidden="true"><?= Icon::svg('sparkles') ?></span>
            <span class="cta-spark cta-spark-b" aria-hidden="true"><?= Icon::svg('sparkles') ?></span>
            <h2 id="cta-title">Mais tempo para o que importa.<br><em>Menos tempo apagando incêndio.</em></h2>
            <p>O <?= marca_html() ?> está em desenvolvimento. Vem acompanhar essa próxima fase.</p>
            <a class="btn btn-dark btn-lg" href="/cadastro">Quero começar <?= Icon::svg('arrow-up-right', 'icon-move') ?></a>
        </div>
    </div>
</section>
