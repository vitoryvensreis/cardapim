<?php
/**
 * Fechamentos de caixa do periodo, com sobras e faltas. O detalhe de cada caixa abre no modo caixa.
 * Espera: $fechamentos (FinanceiroService::fechamentos), $caixaBase, $periodo, $base, $abaFinanceiro.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reais = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$quando = static fn (?string $d): string => $d ? date('d/m/Y H:i', (int) strtotime($d)) : '—';
$caminhoPeriodo = '/financeiro/fechamentos';
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Financeiro</h1>
            <p>Cada caixa aberto no período: quanto vendeu e se sobrou ou faltou dinheiro na gaveta.</p>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>
    <?php require __DIR__ . '/_periodo.php'; ?>

    <?php if ($fechamentos['caixas'] === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('lock') ?>
                <strong>Nenhum caixa aberto neste período</strong>
                <p>Quando a equipe abrir e fechar o caixa, cada turno aparece aqui com o resultado da conferência.</p>
            </div>
        </section>
    <?php else: ?>
        <div class="indicadores fin-indicadores-3">
            <div class="indicador">
                <span class="indicador-topo"><?= Icon::svg('lock') ?>Caixas fechados</span>
                <strong><?= (int) $fechamentos['fechados'] ?></strong>
                <small>vendas de <?= $e($reais($fechamentos['vendas'])) ?></small>
            </div>
            <div class="indicador <?= $fechamentos['sobras'] > 0 ? 'is-atencao' : 'is-ok' ?>">
                <span class="indicador-topo"><?= Icon::svg('plus') ?>Sobrou na gaveta</span>
                <strong><?= $e($reais($fechamentos['sobras'])) ?></strong>
                <small>somando todos os caixas</small>
            </div>
            <div class="indicador <?= $fechamentos['faltas'] > 0 ? 'is-alerta' : 'is-ok' ?>">
                <span class="indicador-topo"><?= Icon::svg('minus') ?>Faltou na gaveta</span>
                <strong><?= $e($reais($fechamentos['faltas'])) ?></strong>
                <small>somando todos os caixas</small>
            </div>
        </div>

        <ul class="lista lista-caixas lista-fechamentos">
            <li class="lista-cabecalho" aria-hidden="true"><span>Caixa</span><span>Vendas</span><span>Dinheiro contado</span><span>Diferença</span><span class="lista-cab-acoes">Ver</span></li>
            <?php foreach ($fechamentos['caixas'] as $cx):
                $resumoCaixa = json_decode((string) ($cx['resumo'] ?? ''), true);
                $dif = $cx['diferenca'] !== null ? Numero::centavos((string) $cx['diferenca']) : null;
                $aberto = $cx['status'] === 'aberto'; ?>
                <li class="lista-item">
                    <span class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg($aberto ? 'lock-open' : 'lock', 'icon-sm') ?></span>
                        <span class="lista-titulo"><strong><?= $e($quando((string) $cx['aberto_em'])) ?></strong>
                            <small><?= $aberto ? 'Aberto agora · ' . $e((string) $cx['aberto_por']) : 'Fechado ' . $e($quando($cx['fechado_em'])) . ' · ' . $e((string) $cx['fechado_por']) ?></small></span>
                    </span>
                    <span class="lista-dado" data-rotulo="Vendas"><?= is_array($resumoCaixa) ? $e($reais((int) ($resumoCaixa['vendas'] ?? 0))) : '<span class="texto-suave">Em andamento</span>' ?></span>
                    <span class="lista-dado" data-rotulo="Dinheiro contado"><?= $cx['dinheiro_contado'] !== null ? $e(Numero::reais((string) $cx['dinheiro_contado'])) : '—' ?></span>
                    <span class="lista-dado" data-rotulo="Diferença">
                        <?php if ($dif === null): ?>—<?php elseif ($dif === 0): ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Certo</span>
                        <?php elseif ($dif > 0): ?><span class="etiqueta etiqueta-atencao"><?= Icon::svg('plus') ?>Sobrou <?= $e($reais($dif)) ?></span>
                        <?php else: ?><span class="etiqueta etiqueta-alerta"><?= Icon::svg('minus') ?>Faltou <?= $e($reais(-$dif)) ?></span><?php endif; ?>
                    </span>
                    <span class="lista-acoes"><a class="btn btn-outline btn-sm" href="<?= $e($caixaBase . ($aberto ? '' : '/historico/' . (int) $cx['id'])) ?>"><?= Icon::svg('eye', 'icon-sm') ?>Ver</a></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
