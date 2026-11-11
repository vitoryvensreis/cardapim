<?php
/** Detalhe de um caixa: resumo do fechamento, formas, entradas/saidas e recebimentos. Espera: $caixa, $resumo, $movimentos, $pagamentos, $base. */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$fechado = $caixa['status'] === 'fechado';
// Fechado: usa o resumo gravado no fechamento (nao muda depois).
$gravado = $fechado ? json_decode((string) $caixa['resumo'], true) : null;
$formas = is_array($gravado) ? $gravado['formas'] : $resumo['formas'];
$vendas = is_array($gravado) ? (int) $gravado['vendas'] : $resumo['vendas'];
$esperado = $fechado ? Numero::centavos((string) $caixa['dinheiro_esperado']) : $resumo['esperado'];
$dif = $fechado ? Numero::centavos((string) $caixa['diferenca']) : null;
$todas = CaixaService::ENTRADAS + CaixaService::SAIDAS;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Caixa de <?= $e(date('d/m/Y', (int) strtotime((string) $caixa['aberto_em']))) ?></h1>
            <p>Aberto às <?= $e(date('H:i', (int) strtotime((string) $caixa['aberto_em']))) ?> por <?= $e((string) $caixa['aberto_por']) ?><?php if ($fechado): ?>, fechado <?= $e(date('d/m \à\s H:i', (int) strtotime((string) $caixa['fechado_em']))) ?> por <?= $e((string) $caixa['fechado_por']) ?><?php endif; ?>.</p>
        </div>
        <div class="painel-acoes">
            <button class="btn btn-outline btn-sm" type="button" data-imprimir><?= Icon::svg('printer', 'icon-sm') ?>Imprimir</button>
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/historico') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Fechamentos</a>
        </div>
    </header>

    <?php if ($fechado): ?>
        <div class="caixa-conferencia <?= $dif === 0 ? 'is-ok' : ($dif > 0 ? 'is-sobra' : 'is-falta') ?>" role="status">
            <?= Icon::svg($dif === 0 ? 'circle-check' : 'info', 'icon-lg') ?>
            <div>
                <strong><?= $dif === 0 ? 'O dinheiro bateu certinho' : ($dif > 0 ? 'Sobrou ' . $e($r($dif)) : 'Faltou ' . $e($r(abs($dif)))) ?></strong>
                <p>Esperado na gaveta: <?= $e($r($esperado)) ?> · Contado: <?= $e(Numero::reais((string) $caixa['dinheiro_contado'])) ?></p>
                <?php if (!empty($caixa['observacao'])): ?><p>Observação: <?= $e((string) $caixa['observacao']) ?></p><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="caixa-colunas">
        <section class="bloco" aria-labelledby="titulo-formas-h">
            <div class="bloco-cabecalho"><h2 id="titulo-formas-h"><?= Icon::svg('wallet') ?>Vendas: <?= $e($r($vendas)) ?></h2></div>
            <dl class="caixa-formas">
                <div class="caixa-forma caixa-forma-base"><dt>Troco inicial</dt><dd><?= $e(Numero::reais((string) $caixa['valor_abertura'])) ?></dd></div>
                <?php foreach (CaixaService::FORMAS as $forma => $rotulo): ?>
                    <div class="caixa-forma"><dt><?= $e($rotulo) ?></dt><dd><?= $e($r((int) ($formas[$forma] ?? 0))) ?></dd></div>
                <?php endforeach; ?>
                <div class="caixa-forma"><dt>Entradas</dt><dd class="valor-mais">+<?= $e($r($resumo['entradas'])) ?></dd></div>
                <div class="caixa-forma"><dt>Saídas</dt><dd class="valor-menos">−<?= $e($r($resumo['saidas'])) ?></dd></div>
                <div class="caixa-forma caixa-forma-total"><dt>Dinheiro esperado na gaveta</dt><dd><?= $e($r($esperado)) ?></dd></div>
            </dl>
        </section>
        <section class="bloco" aria-labelledby="titulo-mov-h">
            <div class="bloco-cabecalho"><h2 id="titulo-mov-h"><?= Icon::svg('arrow-left-right') ?>Entradas e saídas</h2></div>
            <?php if ($movimentos === []): ?><p class="texto-suave">Nenhuma.</p><?php else: ?>
                <ul class="caixa-linhas">
                    <?php foreach ($movimentos as $m): $entrada = $m['tipo'] === 'entrada'; ?>
                        <li><span><strong><?= $e($todas[$m['categoria']] ?? (string) $m['categoria']) ?></strong><small><?= $m['descricao'] ? $e((string) $m['descricao']) . ' · ' : '' ?><?= $e(date('H:i', (int) strtotime((string) $m['criado_em']))) ?> · <?= $e((string) $m['autor']) ?></small></span>
                            <span class="caixa-valor <?= $entrada ? 'valor-mais' : 'valor-menos' ?>"><?= $entrada ? '+' : '−' ?><?= $e(Numero::reais((string) $m['valor'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <section class="bloco" aria-labelledby="titulo-rec-h">
        <div class="bloco-cabecalho"><h2 id="titulo-rec-h"><?= Icon::svg('receipt') ?>Recebimentos (<?= count($pagamentos) ?>)</h2></div>
        <?php if ($pagamentos === []): ?><p class="texto-suave">Nenhum recebimento neste caixa.</p><?php else: ?>
            <ul class="caixa-linhas">
                <?php foreach ($pagamentos as $pg): ?>
                    <li><span><strong>#<?= (int) $pg['numero'] ?></strong> <?= $pg['mesa_numero'] !== null ? 'Mesa ' . (int) $pg['mesa_numero'] : ($pg['tipo'] === 'balcao' ? 'Balcão' : ($pg['tipo'] === 'entrega' ? 'Entrega' : 'Retirada')) ?>
                        <small><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?><?= Numero::centavos((string) $pg['troco']) > 0 ? ' (troco ' . $e(Numero::reais((string) $pg['troco'])) . ')' : '' ?> · <?= $e(date('H:i', (int) strtotime((string) $pg['criado_em']))) ?> · <?= $e((string) $pg['autor']) ?></small></span>
                        <span class="caixa-valor"><?= $e(Numero::reais((string) $pg['valor'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
