<?php
/**
 * Situação da nota fiscal (NFC-e) de um pedido. Só aparece quando o restaurante emite nota
 * ($notaFiscal vem de FiscalEmissao::situacao, null com o módulo desligado).
 * Espera: $notaFiscal, $base, $csrf, $idPedido, $voltar ('comanda' | 'historico' | 'delivery').
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Fiscal\FiscalEmissao;

if (empty($notaFiscal)) {
    return;
}
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$st = (string) $notaFiscal['status'];
$classe = match ($st) {
    'autorizada' => 'etiqueta-ok',
    'rejeitada', 'bloqueada' => 'etiqueta-alerta',
    'cancelada' => 'etiqueta-neutra',
    default => 'etiqueta-atencao',
};
$icone = match ($st) {
    'autorizada' => 'circle-check',
    'rejeitada', 'bloqueada' => 'circle-x',
    'cancelada' => 'circle-x',
    default => 'clock',
};
$explica = match ($st) {
    'pendente', 'enviando' => 'A nota está sendo emitida. Não precisa fazer nada.',
    'incerta' => 'Aguardando a confirmação da Sefaz. O sistema confere sozinho.',
    'autorizada' => 'Nota emitida' . ($notaFiscal['numero'] !== null ? ' nº ' . (int) $notaFiscal['numero'] . ', série ' . (int) $notaFiscal['serie'] : '') . ((int) $notaFiscal['ambiente'] !== 1 ? ' em ambiente de teste (sem valor fiscal).' : '.'),
    'rejeitada' => 'A Sefaz recusou a nota: ' . (string) ($notaFiscal['motivo'] ?? 'motivo não informado') . '. Corrija e tente de novo.',
    'bloqueada' => 'Falta acertar o cadastro fiscal: ' . (string) ($notaFiscal['motivo'] ?? 'confira os produtos em Gerência > Nota fiscal') . '.',
    'cancelada' => 'A nota desta venda foi cancelada.',
    default => '',
};
$podeReenviar = in_array($st, ['rejeitada', 'bloqueada'], true);
?>
<section class="nota-fiscal-bloco" data-nota-fiscal="<?= (int) $idPedido ?>" data-nota-rotulos="<?= $e((string) json_encode(FiscalEmissao::ROTULOS, JSON_UNESCAPED_UNICODE)) ?>" aria-live="polite">
    <span class="etiqueta <?= $e($classe) ?>" data-nota-etiqueta><?= Icon::svg($icone) ?><span data-nota-rotulo><?= $e(FiscalEmissao::ROTULOS[$st] ?? 'Nota fiscal') ?></span></span>
    <p class="nota-fiscal-texto" data-nota-texto><?= $e($explica) ?></p>
    <?php if ($podeReenviar): ?>
        <form method="post" action="<?= $e($base . '/fiscal/' . (int) $idPedido . '/reenviar') ?>" data-nota-reenviar>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="voltar" value="<?= $e((string) $voltar) ?>">
            <button type="submit" class="btn btn-outline btn-sm"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Tentar de novo</button>
        </form>
    <?php endif; ?>
    <?php if ($st === 'autorizada'): ?>
        <form method="post" action="<?= $e($base . '/fiscal/' . (int) $idPedido . '/imprimir') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="voltar" value="<?= $e((string) $voltar) ?>">
            <button type="submit" class="btn btn-outline btn-sm"><?= Icon::svg('printer', 'icon-sm') ?>Imprimir nota</button>
        </form>
    <?php endif; ?>
</section>
