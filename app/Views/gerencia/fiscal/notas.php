<?php
/**
 * Notas emitidas (NFC-e) do restaurante. Espera: $dados (GestaoNotas::lista), $ambiente, $seriePadrao, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Fiscal\FiscalEmissao;
use Cardapim\Services\Fiscal\GestaoNotas;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = $base . '/fiscal';
$reais = static fn (string $v): string => 'R$ ' . number_format((float) $v, 2, ',', '.');
$classe = static fn (string $s): string => match ($s) { 'autorizada' => 'etiqueta-ok', 'rejeitada', 'bloqueada' => 'etiqueta-alerta', 'cancelada' => 'etiqueta-neutra', default => 'etiqueta-atencao' };
$r = $dados['resumo'];
$atencao = ($r['rejeitada'] ?? 0) + ($r['bloqueada'] ?? 0);
$paginas = max(1, (int) ceil($dados['total'] / GestaoNotas::POR_PAGINA));
$query = static fn (array $mais): string => '?' . http_build_query(array_filter(['mes' => $dados['mes'], 'status' => $dados['status'], 'busca' => $dados['busca']] + $mais, static fn ($v) => $v !== '' && $v !== null));
$meses = [];
for ($i = 0; $i < 13; $i++) { $t = strtotime(date('Y-m-01') . " -$i month"); $meses[date('Y-m', $t)] = date('m/Y', $t); }
?>
<div class="painel-page notas-fiscais">
    <header class="painel-cabecalho">
        <div><a class="voltar-link" href="<?= $e($url) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Central fiscal</a><h1>Notas emitidas</h1>
            <p>Acompanhe as notas fiscais do consumidor (NFC-e) geradas pelas vendas.<?= $ambiente !== 1 ? ' Você está em modo de teste: as notas não têm valor fiscal.' : '' ?></p></div>
        <a class="btn btn-outline" href="<?= $e($url . '/notas/exportar?mes=' . rawurlencode($dados['mes'])) ?>"><?= Icon::svg('download', 'icon-sm') ?>Baixar XMLs do mês</a>
    </header>

    <?php if ($atencao > 0): ?>
        <div class="notice notice-warning" role="status"><?= Icon::svg('triangle-exclamation-point') ?><div><strong><?= $atencao === 1 ? '1 nota precisa de atenção' : $e($atencao) . ' notas precisam de atenção' ?></strong><p>Abra a nota para ver o motivo, corrija e clique em "Tentar de novo".</p>
            <a class="btn btn-outline btn-sm" href="<?= $e($url . '/notas' . $query(['status' => 'rejeitada'])) ?>">Ver rejeitadas</a> <a class="btn btn-outline btn-sm" href="<?= $e($url . '/notas' . $query(['status' => 'bloqueada'])) ?>">Ver com pendência</a></div></div>
    <?php endif; ?>

    <section class="bloco notas-resumo" aria-label="Resumo do mês">
        <div><span>Autorizadas</span><strong><?= $e($r['autorizada'] ?? 0) ?></strong></div>
        <div><span>Na fila ou confirmando</span><strong><?= $e(($r['pendente'] ?? 0) + ($r['enviando'] ?? 0) + ($r['incerta'] ?? 0)) ?></strong></div>
        <div><span>Precisam de atenção</span><strong><?= $e($atencao) ?></strong></div>
        <div><span>Canceladas</span><strong><?= $e($r['cancelada'] ?? 0) ?></strong></div>
    </section>

    <section class="bloco">
        <form method="get" action="<?= $e($url . '/notas') ?>" class="notas-filtros">
            <label>Mês<select name="mes"><?php foreach ($meses as $v => $rot): ?><option value="<?= $e($v) ?>"<?= $v === $dados['mes'] ? ' selected' : '' ?>><?= $e($rot) ?></option><?php endforeach; ?></select></label>
            <label>Situação<select name="status"><option value="">Todas</option><?php foreach (FiscalEmissao::ROTULOS as $k => $rot): ?><option value="<?= $e($k) ?>"<?= $k === $dados['status'] ? ' selected' : '' ?>><?= $e($rot) ?></option><?php endforeach; ?></select></label>
            <label>Número da nota ou do pedido<input name="busca" inputmode="numeric" maxlength="44" value="<?= $e($dados['busca']) ?>" placeholder="Ex.: 125"></label>
            <button class="btn btn-outline" type="submit"><?= Icon::svg('search', 'icon-sm') ?>Filtrar</button>
        </form>

        <?php if ($dados['notas'] === []): ?>
            <div class="notas-vazio"><?= Icon::svg('receipt', 'icon-lg') ?><p><strong>Nenhuma nota neste filtro.</strong></p><p class="texto-suave">As notas aparecem aqui quando uma venda é paga com a emissão automática ligada.</p></div>
        <?php else: ?>
            <ul class="notas-lista">
                <?php foreach ($dados['notas'] as $n): ?>
                    <li>
                        <a href="<?= $e($url . '/notas/' . (int) $n['id']) ?>" class="notas-linha">
                            <span class="notas-numero"><strong><?= $n['numero'] !== null ? 'Nota ' . $e($n['numero']) : 'Sem número' ?></strong><small>Pedido #<?= $e($n['pedido_numero']) ?> · <?= $e(FiscalEmissao::ORIGENS[$n['origem']] ?? $n['origem']) ?> · <?= $e(date('d/m H:i', (int) strtotime((string) $n['criado_em']))) ?></small></span>
                            <span class="etiqueta <?= $e($classe((string) $n['status'])) ?>"><?= $e(FiscalEmissao::ROTULOS[$n['status']] ?? $n['status']) ?></span>
                            <span class="notas-valor"><?= $e($reais((string) $n['valor'])) ?></span>
                        </a>
                        <?php if (in_array($n['status'], ['rejeitada', 'bloqueada'], true) && $n['motivo']): ?><p class="notas-motivo"><?= $e(mb_strimwidth((string) $n['motivo'], 0, 160, '...')) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($paginas > 1): ?>
                <nav class="notas-paginas" aria-label="Páginas">
                    <?php if ($dados['pagina'] > 1): ?><a class="btn btn-outline btn-sm" href="<?= $e($url . '/notas' . $query(['pagina' => $dados['pagina'] - 1])) ?>">Anterior</a><?php endif; ?>
                    <span>Página <?= $e($dados['pagina']) ?> de <?= $e($paginas) ?></span>
                    <?php if ($dados['pagina'] < $paginas): ?><a class="btn btn-outline btn-sm" href="<?= $e($url . '/notas' . $query(['pagina' => $dados['pagina'] + 1])) ?>">Próxima</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($ambiente !== 3): ?>
    <details class="bloco notas-inutilizar">
        <summary><?= Icon::svg('ban', 'icon-sm') ?>Inutilizar números que não serão usados</summary>
        <p class="texto-suave">Use quando uma nota foi rejeitada e não será reenviada com o mesmo número, ou quando a numeração pulou. A SEFAZ exige o registro desses números. Notas rejeitadas na faixa recebem um número novo se você tentar de novo.</p>
        <form method="post" action="<?= $e($url . '/notas/inutilizar') ?>" class="fiscal-form" data-confirm="Os números informados não poderão mais ser usados." data-confirm-titulo="Inutilizar números?" data-confirm-acao="Inutilizar" data-confirm-perigo="1">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="notas-campos">
                <label>Série<input name="serie" type="number" min="0" max="999" required value="<?= $e($seriePadrao) ?>"></label>
                <label>Do número<input name="inicio" type="number" min="1" max="999999999" required></label>
                <label>Até o número <small>(opcional)</small><input name="fim" type="number" min="1" max="999999999"></label>
            </div>
            <label for="inut-just">Motivo (15 a 255 letras)</label>
            <textarea id="inut-just" name="justificativa" minlength="15" maxlength="255" rows="2" required placeholder="Ex.: Nota rejeitada por erro de cadastro, número não utilizado"></textarea>
            <button class="btn btn-outline" type="submit">Inutilizar números</button>
        </form>
    </details>
    <?php endif; ?>
</div>
