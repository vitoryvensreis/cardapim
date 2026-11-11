<?php
/**
 * Entradas e saidas do periodo (extrato): vendas recebidas por dia e forma, movimentos da gaveta e contas baixadas.
 * Espera: $linhas, $sentido (tudo|entrada|saida), $totais, $total, $periodo, $base, $abaFinanceiro.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reais = static fn (int $c): string => ($c < 0 ? '- ' : '') . Numero::reais(CaixaService::dec(abs($c)));
$caminhoPeriodo = '/financeiro/extrato';
$extrasPeriodo = $sentido === 'tudo' ? [] : ['sentido' => $sentido];
$filtrosSentido = ['tudo' => 'Tudo', 'entrada' => 'Só entradas', 'saida' => 'Só saídas'];
$tipos = ['venda' => 'Vendas', 'caixa' => 'Caixa', 'conta' => 'Conta'];
$diaAtual = null;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Financeiro</h1>
            <p>Tudo que entrou e saiu, do mais recente para o mais antigo.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/financeiro/relatorio?' . $periodo->query()) ?>" download><?= Icon::svg('file-spreadsheet', 'icon-sm') ?>Baixar planilha</a>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>
    <?php require __DIR__ . '/_periodo.php'; ?>

    <div class="indicadores fin-indicadores-3">
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('banknote-arrow-up') ?>Entradas</span>
            <strong><?= $e($reais($totais['entrada'])) ?></strong>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('banknote-arrow-down') ?>Saídas</span>
            <strong><?= $e($reais($totais['saida'])) ?></strong>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('piggy-bank') ?>Gaveta e cofre</span>
            <strong><?= $e($reais($totais['transferencia'])) ?></strong>
            <small>sangria e reforço de troco (não contam)</small>
        </div>
    </div>

    <?php if ($total === 0): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('arrow-right-left') ?>
                <strong>Nenhuma entrada ou saída neste período</strong>
                <p>Vendas pagas, sangrias, despesas do caixa e contas pagas aparecem aqui.</p>
            </div>
        </section>
    <?php else: ?>
        <div class="ferramentas">
            <nav class="filtros" aria-label="Filtrar entradas e saídas">
                <?php foreach ($filtrosSentido as $filtroChave => $filtroRotulo): ?>
                    <a href="<?= $e($base . '/financeiro/extrato?' . http_build_query([...$periodo->parametros(), ...($filtroChave === 'tudo' ? [] : ['sentido' => $filtroChave])])) ?>"<?= $sentido === $filtroChave ? ' aria-current="true"' : '' ?>><?= $e($filtroRotulo) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ($linhas === []): ?>
            <div class="bloco"><div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong>Nada neste filtro</strong></div></div>
        <?php else: ?>
            <ul class="lista lista-extrato">
                <li class="lista-cabecalho" aria-hidden="true"><span>Descrição</span><span>Categoria</span><span>Quem</span><span class="lista-cab-acoes">Valor</span></li>
                <?php foreach ($linhas as $l):
                    $saida = $l['sentido'] === 'saida';
                    if ($l['dia'] !== $diaAtual):
                        $diaAtual = $l['dia']; ?>
                        <li class="fin-dia"><?= Icon::svg('calendar', 'icon-sm') ?><?= $e(date('d/m/Y', (int) strtotime($l['dia']))) ?></li>
                    <?php endif; ?>
                    <li class="lista-item">
                        <div class="lista-principal">
                            <span class="lista-icone fin-sentido <?= $l['transferencia'] ? 'is-transferencia' : ($saida ? 'is-saida' : 'is-entrada') ?>" aria-hidden="true"><?= Icon::svg($l['transferencia'] ? 'arrow-right-left' : ($saida ? 'arrow-down-left' : 'arrow-up-right'), 'icon-sm') ?></span>
                            <span class="lista-titulo">
                                <?php if ($l['link'] !== null): ?>
                                    <a href="<?= $e($base . '/' . $l['link']) ?>"><strong><?= $e($l['descricao']) ?></strong></a>
                                <?php else: ?>
                                    <strong><?= $e($l['descricao']) ?></strong>
                                <?php endif; ?>
                                <small><?= $l['transferencia'] ? 'Transferência' : ($saida ? 'Saída' : 'Entrada') ?> · <?= $e($tipos[$l['tipo']] ?? '') ?><?= $l['tipo'] === 'caixa' ? ' · ' . $e(substr($l['quando'], 11, 5)) : '' ?></small>
                            </span>
                        </div>
                        <span class="lista-dado" data-rotulo="Categoria"><?= $e($l['categoria']) ?></span>
                        <span class="lista-dado" data-rotulo="Quem"><?= $l['autor'] !== '' ? $e($l['autor']) : '<span class="texto-suave">—</span>' ?></span>
                        <span class="lista-dado fin-valor <?= $l['transferencia'] ? '' : ($saida ? 'fin-negativo' : 'fin-positivo') ?>" data-rotulo="Valor"><?= $saida ? '- ' : '+ ' ?><?= $e($reais($l['valor'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
