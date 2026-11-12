<?php
/**
 * Dashboard da plataforma: assinaturas, restaurantes, pedidos de toda a plataforma e saude do sistema.
 * Espera: $periodo, $periodos, $restaurantes, $pedidos, $anterior, $grafico, $origens, $maisPedidos, $assinaturas,
 * $faturas, $ambiente, $suporte, $saude, $usuario.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\RestauranteAdminService;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$plural = static fn (int $n, string $um, string $varios): string => $n . ' ' . ($n === 1 ? $um : $varios);
$meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$hora = (int) date('G');
$saudacaoTexto = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$variacao = $anterior['pedidos'] > 0 ? (int) round(($pedidos['pedidos'] - $anterior['pedidos']) / $anterior['pedidos'] * 100) : null;
$rotuloPeriodo = ['hoje' => 'hoje', '7dias' => 'nos últimos 7 dias', '30dias' => 'nos últimos 30 dias'][$periodo];
$comparado = ['hoje' => 'ontem', '7dias' => 'os 7 dias anteriores', '30dias' => 'os 30 dias anteriores'][$periodo];
$origensRotulo = ['cardapio' => 'Cardápio online', 'balcao' => 'Balcão', 'mesa' => 'Mesas', 'ifood' => 'iFood', 'whatsapp' => 'WhatsApp'];
$selos = [
    'ativo' => ['etiqueta-ok', 'circle-check'],
    'pendente' => ['etiqueta-atencao', 'clock'],
    'inadimplente' => ['etiqueta-alerta', 'triangle-exclamation-point'],
    'suspenso' => ['etiqueta-alerta', 'pause'],
    'inativo' => ['etiqueta-neutra', 'power'],
];
$tr = $saude['tempo_real']['situacao'];
$ifood = $saude['ifood'];
$ifoodRodando = $ifood['ultima'] !== null && time() - (int) strtotime($ifood['ultima']) <= 180;
$itensSaude = [
    ['Banco de dados', 'database', true, 'Funcionando', null],
    ['Tempo real', 'radio', $tr === 'operacional', $tr === 'operacional' ? 'Ao vivo' : ($tr === 'parado' ? 'Parado: telas atualizam a cada minuto' : 'Não iniciado'), '/status'],
    ['Asaas da plataforma', 'qr-code', $saude['asaas'] === 'conectado', $saude['asaas'] === 'conectado' ? 'Conectado' : 'Desconectado: assinaturas sem cobrança', '/painel/integracoes/asaas'],
    ['WhatsApp da plataforma', 'message-circle', $saude['whatsapp'] === 'conectado', $saude['whatsapp'] === 'conectado' ? 'Conectado' : 'Desconectado: clientes não recebem o código de login', '/painel/integracoes/whatsapp'],
    ['iFood', 'shopping-bag', $ifood['liberado'] && ($ifood['lojas'] === 0 || $ifoodRodando),
        !$ifood['liberado'] ? 'Chaves do iFood não configuradas' : ($ifood['lojas'] === 0 ? 'Nenhuma loja conectada ainda' : ($ifoodRodando ? $plural($ifood['lojas'], 'loja conectada', 'lojas conectadas') . ', rotina rodando' : 'Rotina parada há mais de 3 minutos')), null],
    ['E-mail do sistema', 'mail', $saude['email'] === 'smtp', $saude['email'] === 'smtp' ? 'Servidor de e-mail configurado' : 'Sem servidor de e-mail (pode cair no spam)', '/painel/configuracoes#email'],
];
$problemas = count(array_filter($itensSaude, static fn (array $i): bool => !$i[2]));
$passoRotulo = count($grafico) > 20 ? 5 : 2;
$ultimo = count($grafico) - 1;
$barras = array_map(static fn (array $g, int $i): array => [
    // Com muitas barras, so uma data a cada 2 ou 5 dias (a ultima sempre), para os rotulos nao se encostarem.
    'rotulo' => ($ultimo - $i) % $passoRotulo === 0 ? (int) substr($g['dia'], 8) . '/' . (int) substr($g['dia'], 5, 2) : '',
    'valor' => $g['pedidos'],
    'dica' => date('d/m', (int) strtotime($g['dia'])) . ': ' . $g['pedidos'] . ($g['pedidos'] === 1 ? ' pedido' : ' pedidos') . ', ' . Numero::reais($g['valor']),
    'destaque' => $g['dia'] === date('Y-m-d'),
], $grafico, array_keys($grafico));
$semPedidosGrafico = array_sum(array_column($grafico, 'pedidos')) === 0;
$maiorRanking = max(1, ...array_column($maisPedidos ?: [['pedidos' => 1]], 'pedidos'));
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <p class="painel-hello"><?= $e($saudacaoTexto) ?>, <?= $e($usuario->nome) ?>!</p>
            <h1>Dashboard</h1>
            <p><?= $e(ucfirst($diasSemana[(int) date('w')] . ', ' . (int) date('j') . ' de ' . $meses[(int) date('n') - 1])) ?> · os números de toda a plataforma</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="/painel/faturas"><?= Icon::svg('receipt', 'icon-sm') ?>Faturas</a>
            <a class="btn btn-primary btn-sm" href="/painel/restaurantes/novo"><?= Icon::svg('plus', 'icon-sm') ?>Novo restaurante</a>
        </div>
    </header>

    <?php if ($faturas['vencidas'] > 0 || $suporte > 0 || $restaurantes['pendente'] > 0): ?>
        <div class="vg-alertas">
            <?php if ($suporte > 0): ?>
                <a class="vg-alerta dash-alerta-atencao" href="/painel/suporte?filtro=aguardando">
                    <?= Icon::svg('life-buoy') ?>
                    <span><strong><?= $suporte === 1 ? '1 chamado esperando resposta' : $suporte . ' chamados esperando resposta' ?></strong><small>Responda em Suporte</small></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                </a>
            <?php endif; ?>
            <?php if ($faturas['vencidas'] > 0): ?>
                <a class="vg-alerta" href="/painel/faturas?situacao=vencida">
                    <?= Icon::svg('triangle-exclamation-point') ?>
                    <span><strong><?= $e($plural($faturas['vencidas'], 'fatura vencida', 'faturas vencidas')) ?></strong><small><?= $e(Numero::reais($faturas['vencido'])) ?> de <?= $e($plural($faturas['inadimplentes'], 'restaurante', 'restaurantes')) ?></small></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                </a>
            <?php endif; ?>
            <?php if ($restaurantes['pendente'] > 0): ?>
                <a class="vg-alerta dash-alerta-atencao" href="/painel/restaurantes?status=pendente">
                    <?= Icon::svg('clock') ?>
                    <span><strong><?= $e($plural($restaurantes['pendente'], 'restaurante esperando o 1º pagamento', 'restaurantes esperando o 1º pagamento')) ?></strong><small>O cardápio só abre depois do pagamento</small></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="indicadores dash-indicadores">
        <a class="indicador vg-link is-ok" href="/painel/planos">
            <span class="indicador-topo"><?= Icon::svg('repeat') ?>Receita por mês</span>
            <strong><?= $e(Numero::reais($assinaturas['mensal'])) ?></strong>
            <small><?= $e($plural($assinaturas['pagas'], 'assinatura paga', 'assinaturas pagas')) ?> · <?= $e($plural($assinaturas['gratis'], 'grátis', 'grátis')) ?></small>
        </a>
        <a class="indicador vg-link" href="/painel/faturas">
            <span class="indicador-topo"><?= Icon::svg('hand-coins') ?>Recebido em <?= $e($meses[(int) date('n') - 1]) ?></span>
            <strong><?= $e(Numero::reais($faturas['recebido_mes'])) ?></strong>
            <small><?= $e(Numero::reais($faturas['a_receber'])) ?> a receber<?= $ambiente === 'sandbox' ? ' · modo de teste' : '' ?></small>
        </a>
        <a class="indicador vg-link" href="/painel/restaurantes?status=ativo">
            <span class="indicador-topo"><?= Icon::svg('store') ?>Restaurantes ativos</span>
            <strong><?= (int) $restaurantes['ativo'] ?><span class="vg-de"> de <?= (int) $restaurantes['total'] ?></span></strong>
            <small><?= $restaurantes['novos'] === 0 ? 'Nenhum novo nos últimos 30 dias' : $e($plural($restaurantes['novos'], 'novo', 'novos')) . ' nos últimos 30 dias' ?></small>
        </a>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('shopping-bag') ?>Pedidos <?= $e($rotuloPeriodo) ?></span>
            <strong><?= (int) $pedidos['pedidos'] ?></strong>
            <?php if ($variacao === null): ?>
                <small><?= $e(Numero::reais($pedidos['valor'])) ?> em pedidos</small>
            <?php else: ?>
                <small class="vg-variacao <?= $variacao >= 0 ? 'is-sobe' : 'is-desce' ?>"><?= Icon::svg($variacao >= 0 ? 'trending-up' : 'trending-down', 'icon-sm') ?><?= $variacao >= 0 ? '+' : '' ?><?= $variacao ?>% em relação a <?= $e($comparado) ?></small>
            <?php endif; ?>
        </div>
    </div>

    <section class="bloco" aria-labelledby="titulo-pedidos">
        <div class="bloco-cabecalho">
            <h2 id="titulo-pedidos"><?= Icon::svg('chart-column') ?>Pedidos na plataforma</h2>
            <nav class="filtros dash-periodos" aria-label="Período">
                <?php foreach ($periodos as $chave => $rotulo): ?>
                    <a href="/painel?periodo=<?= $e($chave) ?>"<?= $periodo === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?></a>
                <?php endforeach; ?>
            </nav>
            <p><?= $e(Numero::reais($pedidos['valor'])) ?> em pedidos <?= $e($rotuloPeriodo) ?>, de <?= $e($plural($pedidos['restaurantes'], 'restaurante', 'restaurantes')) ?>. Cancelados e Pix não pagos ficam de fora.</p>
        </div>
        <?php if ($semPedidosGrafico): ?>
            <div class="lista-vazia">
                <?= Icon::svg('chart-column') ?>
                <strong>Nenhum pedido no período</strong>
                <p>Os pedidos de todos os restaurantes (cardápio, balcão, mesas e iFood) aparecem aqui dia a dia.</p>
            </div>
        <?php else:
            $graficoTitulo = 'Pedidos por dia nos últimos ' . count($grafico) . ' dias. Total: ' . array_sum(array_column($grafico, 'pedidos')) . '.';
            $graficoValor = static fn (int $v): string => (string) $v;
            require __DIR__ . '/../gerencia/visao-geral/_barras.php';
        endif; ?>
        <?php if ($origens !== []): ?>
            <ul class="dash-origens" aria-label="Pedidos por canal">
                <?php foreach ($origens as $origem => $qt): ?>
                    <li><span><?= $e($origensRotulo[$origem] ?? $origem) ?></span><strong><?= (int) $qt ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <div class="blocos-duplos">
        <section class="bloco" aria-labelledby="titulo-ranking">
            <div class="bloco-cabecalho">
                <h2 id="titulo-ranking"><?= Icon::svg('trophy') ?>Quem mais vendeu</h2>
                <p>Restaurantes com mais pedidos <?= $e($rotuloPeriodo) ?>.</p>
            </div>
            <?php if ($maisPedidos === []): ?>
                <div class="lista-vazia">
                    <?= Icon::svg('store') ?>
                    <strong>Ainda sem vendas no período</strong>
                </div>
            <?php else: ?>
                <ol class="vg-ranking">
                    <?php foreach ($maisPedidos as $pos => $r): ?>
                        <li>
                            <span class="vg-posicao" aria-hidden="true"><?= $pos + 1 ?></span>
                            <span class="vg-ranking-nome"><a href="/painel/restaurantes/<?= (int) $r['id'] ?>"><?= $e($r['nome']) ?></a><small><?= $e($plural($r['pedidos'], 'pedido', 'pedidos')) ?> · <?= $e(Numero::reais($r['valor'])) ?></small></span>
                            <progress max="<?= $maiorRanking ?>" value="<?= (int) $r['pedidos'] ?>" aria-hidden="true"></progress>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-situacao">
            <div class="bloco-cabecalho">
                <h2 id="titulo-situacao"><?= Icon::svg('store') ?>Restaurantes por situação</h2>
                <a class="btn-icone" href="/painel/restaurantes"><?= Icon::svg('arrow-up-right', 'icon-sm') ?><span>Ver todos</span></a>
            </div>
            <ul class="dash-situacoes">
                <?php foreach ($selos as $st => [$classe, $icone]): ?>
                    <li>
                        <a href="/painel/restaurantes?status=<?= $e($st) ?>">
                            <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e(RestauranteAdminService::rotuloStatus($st)) ?></span>
                            <strong><?= (int) $restaurantes[$st] ?></strong>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <section class="bloco" aria-labelledby="titulo-saude">
        <div class="bloco-cabecalho">
            <h2 id="titulo-saude"><?= Icon::svg('activity') ?>Saúde do sistema</h2>
            <?php if ($problemas === 0): ?>
                <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Tudo funcionando</span>
            <?php else: ?>
                <span class="etiqueta etiqueta-atencao"><?= Icon::svg('triangle-exclamation-point') ?><?= $problemas === 1 ? '1 ponto de atenção' : $problemas . ' pontos de atenção' ?></span>
            <?php endif; ?>
        </div>
        <ul class="dash-saude">
            <?php foreach ($itensSaude as [$nome, $icone, $ok, $texto, $link]): ?>
                <li class="<?= $ok ? 'is-ok' : 'is-atencao' ?>">
                    <span class="dash-saude-icone" aria-hidden="true"><?= Icon::svg($icone) ?></span>
                    <span class="dash-saude-texto"><strong><?= $e($nome) ?></strong><small><?= Icon::svg($ok ? 'circle-check' : 'triangle-exclamation-point', 'icon-sm') ?><?= $e($texto) ?></small></span>
                    <?php if ($link !== null): ?><a class="btn-icone" href="<?= $e($link) ?>"><?= Icon::svg('chevron-right', 'icon-sm') ?><span><?= $ok ? 'Abrir' : 'Resolver' ?></span></a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
