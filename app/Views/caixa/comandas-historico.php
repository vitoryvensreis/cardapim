<?php
/** Comandas do periodo (mesa e balcao). Espera: $periodo, $status, $lista (HistoricoComandas::listar), $base. */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\HistoricoComandas;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$hora = static fn (?string $d): string => $d ? date('d/m H:i', (int) strtotime($d)) : '';
$link = static fn (string $p, string $s): string => '?' . http_build_query(['periodo' => $p, 'status' => $s]);
$situacao = [
    'aberto' => ['etiqueta-atencao', 'lock-open', 'Aberta'],
    'concluido' => ['etiqueta-ok', 'circle-check', 'Fechada'],
    'cancelado' => ['etiqueta-neutra', 'circle-x', 'Cancelada'],
];
$r = $lista['resumo'];
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Comandas do período</h1>
            <p>Todas as comandas de mesa e balcão. Toque em uma para ver itens, pagamentos, quem abriu e quem fechou.</p>
        </div>
    </header>

    <nav class="filtros comandas-filtros" aria-label="Período">
        <?php foreach (HistoricoComandas::PERIODOS as $chave => $rotulo): ?>
            <a href="<?= $e($link($chave, $status)) ?>"<?= $chave === $periodo ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="indicadores comandas-indicadores">
        <div class="indicador"><span class="indicador-topo">Abertas agora</span><strong><?= (int) $r['abertas'] ?></strong></div>
        <div class="indicador"><span class="indicador-topo">Fechadas</span><strong><?= (int) $r['fechadas'] ?></strong></div>
        <div class="indicador"><span class="indicador-topo">Canceladas</span><strong><?= (int) $r['canceladas'] ?></strong></div>
        <div class="indicador"><span class="indicador-topo">Vendido nas fechadas</span><strong><?= $e(Numero::reais(CaixaService::dec($r['vendido']))) ?></strong></div>
    </div>

    <nav class="filtros comandas-filtros" aria-label="Situação">
        <?php foreach (HistoricoComandas::STATUS as $chave => $rotulo): ?>
            <a href="<?= $e($link($periodo, $chave)) ?>"<?= $chave === $status ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($lista['comandas'] === []): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('receipt-text') ?><strong>Nenhuma comanda aqui</strong><p>Troque o período ou a situação acima. As comandas aparecem assim que forem abertas no caixa ou pelo garçom.</p></div></section>
    <?php else: ?>
        <ul class="lista lista-comandas">
            <li class="lista-cabecalho" aria-hidden="true"><span>Comanda</span><span>Situação</span><span>Abriu</span><span>Fechou</span><span>Total</span><span class="lista-cab-acoes">Ver</span></li>
            <?php foreach ($lista['comandas'] as $c): [$classe, $icone, $texto] = $situacao[$c['status']] ?? ['etiqueta-neutra', 'minus', (string) $c['status']];
                $fim = $c['status'] === 'concluido' ? $c['concluido_em'] : ($c['status'] === 'cancelado' ? $c['cancelado_em'] : null); ?>
                <li class="lista-item">
                    <span class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg($c['tipo'] === 'mesa' ? 'armchair' : 'store', 'icon-sm') ?></span>
                        <span class="lista-titulo"><strong><?= $e((string) $c['rotulo']) ?></strong><small>Pedido #<?= \Cardapim\Services\Pedidos\NumeroDoDia::de($c) ?> · <?= (int) $c['itens'] ?> <?= (int) $c['itens'] === 1 ? 'item' : 'itens' ?></small></span>
                    </span>
                    <span class="lista-dado" data-rotulo="Situação"><span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e($texto) ?></span></span>
                    <span class="lista-dado" data-rotulo="Abriu"><?= $e($hora((string) $c['criado_em'])) ?><?= $c['aberta_por'] ? '<small> · ' . $e((string) $c['aberta_por']) . '</small>' : '' ?></span>
                    <span class="lista-dado" data-rotulo="Fechou"><?= $fim ? $e($hora((string) $fim)) . ($c['fechada_por'] ? '<small> · ' . $e((string) $c['fechada_por']) . '</small>' : '') : '<span class="texto-suave">—</span>' ?></span>
                    <span class="lista-dado" data-rotulo="Total"><strong><?= $e(Numero::reais((string) $c['total'])) ?></strong></span>
                    <span class="lista-acoes"><a class="btn btn-outline btn-sm" href="<?= $e($base . '/comandas/' . (int) $c['id'] . '/detalhes') ?>" aria-label="Ver detalhes de <?= $e((string) $c['rotulo']) ?>"><?= Icon::svg('eye', 'icon-sm') ?>Ver</a></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($lista['limitado']): ?><p class="texto-suave">Mostrando as <?= HistoricoComandas::LIMITE ?> mais recentes. Use os filtros para ver as outras.</p><?php endif; ?>
    <?php endif; ?>
</div>
