<?php
/**
 * Resumo financeiro do periodo: quanto entrou, quanto saiu, resultado, vendas e de onde veio / para onde foi o dinheiro.
 * Espera: $resumo (FinanceiroService::resumo), $pendencias, $periodo, $base, $abaFinanceiro.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Gerencia\FinanceiroService;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reais = static fn (int $c): string => ($c < 0 ? '- ' : '') . Numero::reais(CaixaService::dec(abs($c)));
$caminhoPeriodo = '/financeiro';
$qs = '?' . $periodo->query();

$entradas = [];
foreach ($resumo['formas'] as $forma => $centavos) {
    if ($centavos > 0) {
        $entradas[] = [CaixaService::FORMAS[$forma] ?? $forma, $centavos, 'Vendas'];
    }
}
if ($resumo['contas_recebidas'] > 0) {
    $entradas[] = ['Contas recebidas', $resumo['contas_recebidas'], 'Contas'];
}
if ($resumo['outras_entradas'] > 0) {
    $entradas[] = ['Outras entradas no caixa', $resumo['outras_entradas'], 'Caixa'];
}
usort($entradas, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
$maxEntrada = max([1, ...array_column($entradas, 1)]);
$maxSaida = max([1, ...array_values($resumo['saidas'])]);
$maxOrigem = max([1, ...array_column($resumo['origens'], 'total')]);
$semMovimento = $resumo['entrou'] === 0 && $resumo['saiu'] === 0 && $resumo['vendas']['pedidos'] === 0 && $resumo['sangria'] === 0;
$positivo = $resumo['resultado'] >= 0;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Financeiro</h1>
            <p>Quanto entrou, quanto saiu e o que sobrou no período.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/financeiro/relatorio' . $qs) ?>" download><?= Icon::svg('file-spreadsheet', 'icon-sm') ?>Baixar planilha</a>
            <a class="btn btn-primary btn-sm" href="<?= $e($base . '/financeiro/contas/nova') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Nova conta</a>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>
    <?php require __DIR__ . '/_periodo.php'; ?>

    <?php if ($pendencias['vencidas'] > 0): ?>
        <div class="notice notice-warning bloco-aviso" role="alert">
            <?= Icon::svg('calendar-clock') ?>
            <div>
                <strong><?= $pendencias['vencidas'] === 1 ? '1 conta a pagar está vencida' : $pendencias['vencidas'] . ' contas a pagar estão vencidas' ?>: <?= $e($reais($pendencias['vencidas_valor'])) ?>.</strong>
                <p><a href="<?= $e($base . '/financeiro/contas?situacao=vencidas') ?>">Ver contas vencidas</a> e marque como paga as que você já pagou.</p>
            </div>
        </div>
    <?php elseif ($pendencias['semana'] > 0): ?>
        <div class="notice notice-info bloco-aviso" role="note">
            <?= Icon::svg('calendar-clock') ?>
            <div>
                <strong><?= $pendencias['semana'] === 1 ? '1 conta vence' : $pendencias['semana'] . ' contas vencem' ?> nos próximos 7 dias: <?= $e($reais($pendencias['semana_valor'])) ?>.</strong>
                <p><a href="<?= $e($base . '/financeiro/contas') ?>">Ver contas em aberto</a></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($semMovimento): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('wallet') ?>
                <strong>Nenhum dinheiro entrou ou saiu neste período</strong>
                <p>As vendas aparecem aqui quando os pedidos são pagos no caixa ou pelo app. Para despesas como aluguel e fornecedores, cadastre uma conta a pagar.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary" href="<?= $e($base . '/financeiro/contas/nova') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar uma conta</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="indicadores">
            <div class="indicador">
                <span class="indicador-topo"><?= Icon::svg('banknote-arrow-up') ?>Entrou</span>
                <strong><?= $e($reais($resumo['entrou'])) ?></strong>
                <small>vendas recebidas e outras entradas</small>
            </div>
            <div class="indicador">
                <span class="indicador-topo"><?= Icon::svg('banknote-arrow-down') ?>Saiu</span>
                <strong><?= $e($reais($resumo['saiu'])) ?></strong>
                <small>despesas e contas pagas</small>
            </div>
            <div class="indicador fin-resultado <?= $positivo ? 'is-ok' : 'is-alerta' ?>">
                <span class="indicador-topo"><?= Icon::svg($positivo ? 'trending-up' : 'trending-down') ?>Resultado</span>
                <strong><?= $e($reais($resumo['resultado'])) ?></strong>
                <small><?= $positivo ? 'sobrou no período' : 'saiu mais do que entrou' ?></small>
            </div>
            <div class="indicador">
                <span class="indicador-topo"><?= Icon::svg('receipt') ?>Vendas</span>
                <strong><?= $e($reais($resumo['vendas']['total'])) ?></strong>
                <small><?= $resumo['vendas']['pedidos'] === 1 ? '1 pedido' : $resumo['vendas']['pedidos'] . ' pedidos' ?><?= $resumo['vendas']['pedidos'] > 0 ? ' · ticket médio ' . $e($reais($resumo['vendas']['ticket'])) : '' ?></small>
            </div>
        </div>

        <div class="blocos-duplos">
            <section class="bloco" aria-labelledby="titulo-entrou">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-entrou"><?= Icon::svg('banknote-arrow-up') ?>Como o dinheiro entrou</h2>
                </div>
                <?php if ($entradas === []): ?>
                    <div class="lista-vazia"><?= Icon::svg('wallet') ?><strong>Nada entrou neste período</strong></div>
                <?php else: ?>
                    <ul class="fin-barras">
                        <?php foreach ($entradas as [$nomeLinha, $valorLinha, $fonteLinha]): ?>
                            <li>
                                <span class="fin-barras-nome"><?= $e($nomeLinha) ?><small><?= $e($fonteLinha) ?></small></span>
                                <strong><?= $e($reais($valorLinha)) ?></strong>
                                <progress class="is-entrada" max="<?= $maxEntrada ?>" value="<?= $valorLinha ?>" aria-hidden="true"></progress>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="bloco" aria-labelledby="titulo-saiu">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-saiu"><?= Icon::svg('banknote-arrow-down') ?>Para onde o dinheiro foi</h2>
                </div>
                <?php if ($resumo['saidas'] === []): ?>
                    <div class="lista-vazia">
                        <?= Icon::svg('circle-check') ?><strong>Nenhuma despesa neste período</strong>
                        <p>Pagou aluguel, luz ou fornecedor fora do caixa? <a href="<?= $e($base . '/financeiro/contas/nova') ?>">Registre uma conta a pagar</a>.</p>
                    </div>
                <?php else: ?>
                    <ul class="fin-barras">
                        <?php foreach ($resumo['saidas'] as $chaveSaida => $valorSaida): ?>
                            <li>
                                <span class="fin-barras-nome"><?= $e(FinanceiroService::rotuloSaida((string) $chaveSaida)) ?><small><?= str_starts_with((string) $chaveSaida, 'caixa:') ? 'Saiu da gaveta' : 'Conta paga' ?></small></span>
                                <strong><?= $e($reais($valorSaida)) ?></strong>
                                <progress class="is-saida" max="<?= $maxSaida ?>" value="<?= $valorSaida ?>" aria-hidden="true"></progress>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($resumo['sangria'] > 0 || $resumo['reforco'] > 0): ?>
                    <p class="fin-nota"><?= Icon::svg('piggy-bank', 'icon-sm') ?><span>
                        <?php if ($resumo['sangria'] > 0): ?><?= $e($reais($resumo['sangria'])) ?> foi levado da gaveta para o cofre (sangria).<?php endif; ?>
                        <?php if ($resumo['reforco'] > 0): ?><?= $e($reais($resumo['reforco'])) ?> foi colocado na gaveta como troco.<?php endif; ?>
                        O dinheiro só mudou de lugar, por isso não conta como entrada nem saída.</span></p>
                <?php endif; ?>
            </section>
        </div>

        <div class="blocos-duplos">
            <section class="bloco" aria-labelledby="titulo-canais">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-canais"><?= Icon::svg('store') ?>Vendas por canal</h2>
                    <p>Pedidos concluídos no período.</p>
                </div>
                <?php if ($resumo['origens'] === []): ?>
                    <div class="lista-vazia"><?= Icon::svg('receipt') ?><strong>Nenhum pedido concluído neste período</strong></div>
                <?php else: ?>
                    <ul class="fin-barras">
                        <?php foreach ($resumo['origens'] as $o): ?>
                            <li>
                                <span class="fin-barras-nome"><?= $e($o['rotulo']) ?><small><?= $o['pedidos'] === 1 ? '1 pedido' : (int) $o['pedidos'] . ' pedidos' ?></small></span>
                                <strong><?= $e($reais($o['total'])) ?></strong>
                                <progress max="<?= $maxOrigem ?>" value="<?= (int) $o['total'] ?>" aria-hidden="true"></progress>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($resumo['vendas']['taxa_entrega'] > 0 || $resumo['vendas']['desconto'] > 0): ?>
                        <p class="fin-nota"><?= Icon::svg('info', 'icon-sm') ?><span>
                            <?= $resumo['vendas']['taxa_entrega'] > 0 ? 'Inclui ' . $e($reais($resumo['vendas']['taxa_entrega'])) . ' de taxas de entrega.' : '' ?>
                            <?= $resumo['vendas']['desconto'] > 0 ? 'Descontos dados: ' . $e($reais($resumo['vendas']['desconto'])) . '.' : '' ?></span></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="bloco" aria-labelledby="titulo-dias">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-dias"><?= Icon::svg('calendar-range') ?>Dia a dia</h2>
                    <p>Só os dias com movimento.</p>
                </div>
                <?php if ($resumo['dias'] === []): ?>
                    <div class="lista-vazia"><?= Icon::svg('calendar') ?><strong>Nenhum dia com movimento</strong></div>
                <?php else: ?>
                    <div class="fin-tabela-rolagem" tabindex="0" role="region" aria-labelledby="titulo-dias">
                        <table class="fin-tabela">
                            <thead><tr><th scope="col">Dia</th><th scope="col">Entrou</th><th scope="col">Saiu</th><th scope="col">Resultado</th></tr></thead>
                            <tbody>
                                <?php foreach ($resumo['dias'] as $d): ?>
                                    <tr>
                                        <th scope="row"><a href="<?= $e($base . '/financeiro/extrato?' . http_build_query(['periodo' => 'personalizado', 'de' => $d['dia'], 'ate' => $d['dia']])) ?>"><?= $e(date('d/m', (int) strtotime($d['dia']))) ?></a><small><?= $d['pedidos'] === 1 ? '1 pedido' : (int) $d['pedidos'] . ' pedidos' ?></small></th>
                                        <td><?= $e($reais($d['entrou'])) ?></td>
                                        <td><?= $d['saiu'] > 0 ? $e($reais($d['saiu'])) : '<span class="texto-suave">—</span>' ?></td>
                                        <td class="<?= $d['resultado'] < 0 ? 'fin-negativo' : 'fin-positivo' ?>"><?= $e($reais($d['resultado'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</div>
