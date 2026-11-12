<?php
/**
 * Faturas das assinaturas. Espera: $faturas, $total, $pagina, $paginas, $filtros, $contagem, $indicadores,
 * $ambiente, $ambientePadrao, $outroAmbiente, $asaasConectado, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$link = static fn (array $mudar): string => '/painel/faturas?' . http_build_query(array_filter([...$filtros, 'pagina' => null, ...$mudar], static fn ($v) => $v !== null && $v !== ''));
$situacoes = [
    'vencida' => ['Vencidas', 'Vencida', 'etiqueta-alerta', 'triangle-exclamation-point'],
    'pendente' => ['Em aberto', 'Em aberto', 'etiqueta-atencao', 'clock'],
    'paga' => ['Pagas', 'Paga', 'etiqueta-ok', 'circle-check'],
    'estornada' => ['Estornadas', 'Estornada', 'etiqueta-neutra', 'rotate-ccw'],
    'cancelada' => ['Canceladas', 'Cancelada', 'etiqueta-neutra', 'x'],
];
$formas = ['PIX' => 'Pix', 'BOLETO' => 'Boleto', 'CREDIT_CARD' => 'Cartão de crédito', 'DEBIT_CARD' => 'Cartão de débito', 'UNDEFINED' => 'Pix ou boleto'];
$periodos = ['mensal' => 'mensal', 'semestral' => 'semestral', 'anual' => 'anual'];
$todas = array_sum($contagem);
$ind = $indicadores;
$plural = static fn (int $n, string $um, string $varios): string => $n . ' ' . ($n === 1 ? $um : $varios);
$meses = ['01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril', '05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto', '09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro'];
$nomeMes = static fn (string $am): string => ($meses[substr($am, 5, 2)] ?? '') . ' de ' . substr($am, 0, 4);
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Faturas</h1>
            <p>Cobranças das assinaturas dos restaurantes. O dinheiro cai na conta Asaas da plataforma e a situação muda sozinha quando o Asaas avisa o pagamento.</p>
        </div>
        <?php if ($asaasConectado): ?>
            <form class="painel-acoes" method="post" action="/painel/faturas/atualizar">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm" type="submit" data-submit-label="Atualizando..."><?= Icon::svg('refresh-cw', 'icon-sm') ?>Atualizar agora</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if (!$asaasConectado): ?>
        <div class="faturas-aviso" role="status">
            <?= Icon::svg('plug-zap') ?>
            <p><strong>O Asaas da plataforma não está conectado.</strong> Sem ele as assinaturas não geram cobrança. <a href="/painel/integracoes/asaas">Conectar o Asaas</a></p>
        </div>
    <?php endif; ?>

    <?php if ($ambiente === 'sandbox'): ?>
        <p class="faturas-ambiente" role="note"><span class="etiqueta etiqueta-atencao"><?= Icon::svg('flask-conical') ?>Modo de teste</span>Estas faturas são do Asaas de teste (sandbox): nenhum dinheiro de verdade.</p>
    <?php endif; ?>

    <div class="indicadores faturas-indicadores">
        <div class="indicador is-ok">
            <span class="indicador-topo"><?= Icon::svg('hand-coins') ?>Recebido em <?= $e($meses[date('m')]) ?></span>
            <strong><?= $e(Numero::reais($ind['recebido_mes'])) ?></strong>
            <small><?= $e($plural($ind['recebidas_mes'], 'fatura paga', 'faturas pagas')) ?></small>
        </div>
        <a class="indicador indicador-link" href="<?= $e($link(['situacao' => 'pendente', 'mes' => null])) ?>">
            <span class="indicador-topo"><?= Icon::svg('clock') ?>A receber</span>
            <strong><?= $e(Numero::reais($ind['a_receber'])) ?></strong>
            <small><?= $e($plural($ind['abertas'], 'fatura em aberto', 'faturas em aberto')) ?></small>
        </a>
        <a class="indicador indicador-link <?= $ind['vencidas'] > 0 ? 'is-alerta' : 'is-ok' ?>" href="<?= $e($link(['situacao' => 'vencida', 'mes' => null])) ?>">
            <span class="indicador-topo"><?= Icon::svg($ind['vencidas'] > 0 ? 'triangle-exclamation-point' : 'circle-check') ?>Vencido</span>
            <strong><?= $e(Numero::reais($ind['vencido'])) ?></strong>
            <small><?= $e($plural($ind['vencidas'], 'fatura vencida', 'faturas vencidas')) ?></small>
        </a>
        <a class="indicador indicador-link <?= $ind['inadimplentes'] > 0 ? 'is-alerta' : 'is-ok' ?>" href="<?= $e($link(['situacao' => 'vencida', 'mes' => null])) ?>">
            <span class="indicador-topo"><?= Icon::svg('store') ?>Restaurantes em atraso</span>
            <strong><?= (int) $ind['inadimplentes'] ?></strong>
            <small><?= $ind['inadimplentes'] === 1 ? 'restaurante com fatura vencida' : 'restaurantes com fatura vencida' ?></small>
        </a>
    </div>

    <nav class="filtros gestao-situacoes" aria-label="Situação das faturas">
        <a href="<?= $e($link(['situacao' => null])) ?>"<?= $filtros['situacao'] === null ? ' aria-current="true"' : '' ?>>Todas <span class="contador"><?= (int) $todas ?></span></a>
        <?php foreach ($situacoes as $st => [$rotulo]): if ($contagem[$st] === 0 && $filtros['situacao'] !== $st) { continue; } ?>
            <a href="<?= $e($link(['situacao' => $st])) ?>"<?= $filtros['situacao'] === $st ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?> <span class="contador"><?= (int) $contagem[$st] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form class="ferramentas gestao-busca" method="get" action="/painel/faturas" role="search">
        <?php foreach (['situacao', 'ambiente'] as $oculto): if ($filtros[$oculto] !== null): ?><input type="hidden" name="<?= $oculto ?>" value="<?= $e((string) $filtros[$oculto]) ?>"><?php endif; endforeach; ?>
        <label class="busca">
            <?= Icon::svg('search', 'icon-sm') ?>
            <span class="sr-only">Buscar fatura</span>
            <input type="search" name="busca" value="<?= $e($filtros['busca']) ?>" placeholder="Restaurante ou número da fatura" autocomplete="off">
        </label>
        <label class="faturas-mes">
            <span>Vencimento em</span>
            <input type="month" name="mes" value="<?= $e((string) $filtros['mes']) ?>" min="2024-01" max="<?= $e(date('Y', strtotime('+2 years')) . '-12') ?>">
        </label>
        <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('search', 'icon-sm') ?>Buscar</button>
        <?php if ($filtros['busca'] !== '' || $filtros['mes'] !== null): ?><a class="btn-icone" href="<?= $e($link(['busca' => null, 'mes' => null])) ?>"><?= Icon::svg('x', 'icon-sm') ?><span>Limpar</span></a><?php endif; ?>
    </form>

    <?php if ($faturas === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('receipt') ?>
                <?php if ($todas === 0 && $filtros['busca'] === '' && $filtros['mes'] === null): ?>
                    <strong>Nenhuma fatura ainda</strong>
                    <p>Quando um restaurante assinar um plano pago, o Asaas gera a cobrança e ela aparece aqui.<?= $asaasConectado ? ' Se faltar alguma, toque em Atualizar agora.' : '' ?></p>
                <?php else: ?>
                    <strong>Nenhuma fatura encontrada</strong>
                    <p><?= $filtros['mes'] !== null ? 'Nada vence em ' . $e($nomeMes($filtros['mes'])) . ' com esses filtros. ' : '' ?><a href="<?= $e($link(['situacao' => null, 'busca' => null, 'mes' => null])) ?>">Ver todas as faturas</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <p class="texto-suave gestao-total"><?= $e($plural($total, 'fatura', 'faturas')) ?><?= $filtros['mes'] !== null ? ' com vencimento em ' . $e($nomeMes($filtros['mes'])) : '' ?><?= $paginas > 1 ? ' · página ' . (int) $pagina . ' de ' . (int) $paginas : '' ?></p>
        <ul class="lista lista-faturas-adm">
            <li class="lista-cabecalho" aria-hidden="true"><span>Restaurante</span><span>Valor</span><span>Vencimento</span><span>Situação</span><span>Pagamento</span><span class="lista-cab-acoes">Fatura</span></li>
            <?php foreach ($faturas as $f):
                [, $rotuloSt, $classe, $icone] = $situacoes[$f['status']] ?? [null, (string) $f['status'], 'etiqueta-neutra', 'circle'];
                $venceu = $f['status'] === 'pendente' && (string) $f['vencimento'] < date('Y-m-d'); ?>
                <li class="lista-item">
                    <div class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg('receipt') ?></span>
                        <span class="lista-titulo">
                            <a href="/painel/restaurantes/<?= (int) $f['id_restaurante'] ?>"><strong><?= $e((string) $f['restaurante_nome']) ?></strong></a>
                            <small>Fatura #<?= (int) $f['id'] ?><?= $f['plano_nome'] !== null ? ' · ' . $e((string) $f['plano_nome']) . ($f['periodo'] !== null ? ' ' . $e($periodos[$f['periodo']] ?? '') : '') : '' ?></small>
                        </span>
                    </div>
                    <span class="lista-dado" data-rotulo="Valor"><strong><?= $e(Numero::reais((string) $f['valor'])) ?></strong></span>
                    <span class="lista-dado" data-rotulo="Vencimento"><?= $e(date('d/m/Y', (int) strtotime((string) $f['vencimento']))) ?></span>
                    <span class="lista-dado" data-rotulo="Situação"><span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e($rotuloSt) ?></span><?php if ($venceu): ?> <span class="texto-suave">aguardando o Asaas</span><?php endif; ?></span>
                    <span class="lista-dado" data-rotulo="Pagamento"><?php if ($f['pago_em'] !== null): ?><?= $e(date('d/m/Y', (int) strtotime((string) $f['pago_em']))) ?><?= $f['forma'] ? ' · ' . $e($formas[$f['forma']] ?? (string) $f['forma']) : '' ?><?php else: ?><span class="texto-suave">—</span><?php endif; ?></span>
                    <div class="lista-acoes">
                        <?php if (!empty($f['link_fatura']) && str_starts_with((string) $f['link_fatura'], 'https://')): ?>
                            <a class="btn-icone" href="<?= $e((string) $f['link_fatura']) ?>" target="_blank" rel="noopener noreferrer"><?= Icon::svg('external-link', 'icon-sm') ?><span>Ver no Asaas</span></a>
                        <?php else: ?>
                            <span class="texto-suave">Sem link</span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php require __DIR__ . '/../_paginacao.php'; ?>
    <?php endif; ?>

    <?php if ($outroAmbiente !== null): ?>
        <p class="faturas-outro texto-suave">
            <?php if ($outroAmbiente === 'sandbox'): ?>
                Há faturas do Asaas de teste. <a href="<?= $e($link(['ambiente' => 'sandbox', 'situacao' => null])) ?>">Ver faturas de teste</a>
            <?php else: ?>
                <a href="<?= $e($link(['ambiente' => $ambientePadrao === 'producao' ? null : 'producao', 'situacao' => null])) ?>">Voltar para as faturas reais</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>
