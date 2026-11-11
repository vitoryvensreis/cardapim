<?php
/**
 * Contas a pagar e a receber. Espera: $contas, $situacao, $situacoes, $tipoFiltro, $hoje, $pendencias, $base, $csrf, $abaFinanceiro, $periodo.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Gerencia\ContaFinanceiraService;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reais = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$data = static fn (string $d): string => date('d/m/Y', (int) strtotime($d));
$linkFiltro = static fn (string $s, ?string $t): string => $base . '/financeiro/contas?' . http_build_query(array_filter(['situacao' => $s === 'abertas' ? null : $s, 'tipo' => $t]));
$tipos = [null => 'Pagar e receber', 'pagar' => 'Só a pagar', 'receber' => 'Só a receber'];
$amanha = date('Y-m-d', (int) strtotime($hoje . ' +1 day'));
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Financeiro</h1>
            <p>Anote o que precisa pagar e receber. Avisamos quando estiver perto de vencer.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/financeiro/contas/nova?tipo=receber') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Conta a receber</a>
            <a class="btn btn-primary btn-sm" href="<?= $e($base . '/financeiro/contas/nova') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Conta a pagar</a>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>

    <div class="indicadores fin-indicadores-3">
        <a class="indicador indicador-link <?= $pendencias['vencidas'] > 0 ? 'is-alerta' : 'is-ok' ?>" href="<?= $e($linkFiltro('vencidas', 'pagar')) ?>">
            <span class="indicador-topo"><?= Icon::svg($pendencias['vencidas'] > 0 ? 'triangle-exclamation-point' : 'circle-check') ?>Vencidas</span>
            <strong><?= $e($reais($pendencias['vencidas_valor'])) ?></strong>
            <small><?= $pendencias['vencidas'] === 0 ? 'nenhuma conta a pagar atrasada' : ($pendencias['vencidas'] === 1 ? '1 conta a pagar atrasada' : $pendencias['vencidas'] . ' contas a pagar atrasadas') ?></small>
        </a>
        <a class="indicador indicador-link <?= $pendencias['semana'] > 0 ? 'is-atencao' : '' ?>" href="<?= $e($linkFiltro('abertas', 'pagar')) ?>">
            <span class="indicador-topo"><?= Icon::svg('calendar-clock') ?>Vencem em 7 dias</span>
            <strong><?= $e($reais($pendencias['semana_valor'])) ?></strong>
            <small><?= $pendencias['semana'] === 1 ? '1 conta a pagar' : $pendencias['semana'] . ' contas a pagar' ?></small>
        </a>
        <a class="indicador indicador-link" href="<?= $e($linkFiltro('abertas', 'receber')) ?>">
            <span class="indicador-topo"><?= Icon::svg('hand-coins') ?>Para receber</span>
            <strong><?= $e($reais($pendencias['receber_valor'])) ?></strong>
            <small><?= $pendencias['receber'] === 1 ? '1 conta em aberto' : $pendencias['receber'] . ' contas em aberto' ?></small>
        </a>
    </div>

    <div class="ferramentas">
        <nav class="filtros" aria-label="Situação das contas">
            <?php foreach ($situacoes as $sitChave => $sitRotulo): ?>
                <a href="<?= $e($linkFiltro($sitChave, $tipoFiltro)) ?>"<?= $situacao === $sitChave ? ' aria-current="true"' : '' ?>><?= $e($sitRotulo) ?></a>
            <?php endforeach; ?>
        </nav>
        <nav class="filtros" aria-label="Tipo de conta">
            <?php foreach ($tipos as $tipoChave => $tipoRotulo): $tipoChave = $tipoChave === '' ? null : $tipoChave; ?>
                <a href="<?= $e($linkFiltro($situacao, $tipoChave)) ?>"<?= $tipoFiltro === $tipoChave ? ' aria-current="true"' : '' ?>><?= $e($tipoRotulo) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if ($contas === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('calendar-clock') ?>
                <?php if ($situacao === 'abertas' && $tipoFiltro === null): ?>
                    <strong>Nenhuma conta em aberto</strong>
                    <p>Cadastre aluguel, luz, fornecedores ou encomendas a receber. Para contas que se repetem todo mês, escolha por quantos meses repetir.</p>
                    <p class="lista-vazia-acao"><a class="btn btn-primary" href="<?= $e($base . '/financeiro/contas/nova') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar conta a pagar</a></p>
                <?php else: ?>
                    <strong>Nenhuma conta neste filtro</strong>
                    <p><a href="<?= $e($base . '/financeiro/contas?situacao=todas') ?>">Ver todas as contas</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <ul class="lista lista-contas">
            <li class="lista-cabecalho" aria-hidden="true"><span>Conta</span><span>Vencimento</span><span>Valor</span><span>Situação</span><span class="lista-cab-acoes">Ações</span></li>
            <?php foreach ($contas as $c):
                $idConta = (int) $c['id'];
                $receber = $c['tipo'] === 'receber';
                $paga = $c['status'] === 'paga';
                $vencida = !$paga && $c['vencimento'] < $hoje;
                $venceHoje = !$paga && $c['vencimento'] === $hoje;
                $venceAmanha = !$paga && $c['vencimento'] === $amanha;
                $verbo = $receber ? 'Recebida' : 'Paga';
            ?>
                <li class="lista-item<?= $paga ? ' is-baixada' : '' ?>">
                    <div class="lista-principal">
                        <span class="lista-icone fin-sentido <?= $receber ? 'is-entrada' : 'is-saida' ?>" aria-hidden="true"><?= Icon::svg($receber ? 'arrow-up-right' : 'arrow-down-left', 'icon-sm') ?></span>
                        <span class="lista-titulo">
                            <a href="<?= $e($base . '/financeiro/contas/' . $idConta) ?>"><strong><?= $e((string) $c['descricao']) ?></strong></a>
                            <small><?= $receber ? 'A receber' : 'A pagar' ?> · <?= $e(ContaFinanceiraService::rotuloCategoria((string) $c['tipo'], (string) $c['categoria'])) ?></small>
                        </span>
                    </div>
                    <span class="lista-dado" data-rotulo="Vencimento"><?= $e($data((string) $c['vencimento'])) ?></span>
                    <span class="lista-dado fin-valor" data-rotulo="Valor"><?= $e(Numero::reais((string) $c['valor'])) ?></span>
                    <span class="lista-dado" data-rotulo="Situação">
                        <?php if ($paga): ?>
                            <span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?><?= $verbo ?> em <?= $e(date('d/m', (int) strtotime((string) $c['pago_em']))) ?></span>
                        <?php elseif ($vencida): ?>
                            <span class="etiqueta etiqueta-alerta"><?= Icon::svg('triangle-exclamation-point') ?>Vencida</span>
                        <?php elseif ($venceHoje): ?>
                            <span class="etiqueta etiqueta-atencao"><?= Icon::svg('clock') ?>Vence hoje</span>
                        <?php elseif ($venceAmanha): ?>
                            <span class="etiqueta etiqueta-atencao"><?= Icon::svg('clock') ?>Vence amanhã</span>
                        <?php else: ?>
                            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('calendar') ?>Em aberto</span>
                        <?php endif; ?>
                    </span>
                    <div class="lista-acoes">
                        <?php if ($paga): ?>
                            <form method="post" action="<?= $e($base . '/financeiro/contas/' . $idConta . '/reabrir') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn-icone" type="submit" title="Voltar para em aberto"><?= Icon::svg('undo-2', 'icon-sm') ?><span>Desfazer</span></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= $e($base . '/financeiro/contas/' . $idConta . '/baixar') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn btn-wine btn-sm" type="submit"><?= Icon::svg('check', 'icon-sm') ?><?= $receber ? 'Recebi' : 'Paguei' ?></button>
                            </form>
                        <?php endif; ?>
                        <a class="btn-icone" href="<?= $e($base . '/financeiro/contas/' . $idConta) ?>" aria-label="Editar <?= $e((string) $c['descricao']) ?>"><?= Icon::svg('pencil', 'icon-sm') ?></a>
                        <form method="post" action="<?= $e($base . '/financeiro/contas/' . $idConta . '/excluir') ?>"
                              data-confirm="A conta será apagada<?= $paga ? ' e deixa de contar no resultado' : '' ?>. Isso não pode ser desfeito."
                              data-confirm-titulo="Excluir &quot;<?= $e((string) $c['descricao']) ?>&quot;?" data-confirm-acao="Excluir conta" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn-icone is-perigo" type="submit" aria-label="Excluir <?= $e((string) $c['descricao']) ?>"><?= Icon::svg('trash', 'icon-sm') ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($contas) >= 300): ?>
            <p class="texto-suave fin-nota-lista">Mostrando as 300 primeiras. Use os filtros para encontrar as outras.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
