<?php
/**
 * Pix pela chave do restaurante na mesa: QR Code e copia e cola do que falta pagar (+ taxa, se houver).
 * Nao ha confirmacao automatica: o garcom confere no app do banco e toca em "Recebi o Pix".
 * Espera: $pix (ComandaPixChave::codigo), $c (detalhes da comanda), $base, $csrf.
 */
use Cardapim\Controllers\GarcomController;
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $centavos): string => GarcomController::reais($centavos);
$url = $base . '/comandas/' . (int) $c['pedido']['id'];
?>
<div class="garcom-pagina garcom-pix">
    <section class="pix-cartao">
        <p class="pix-instrucao"><?= Icon::svg('smartphone', 'icon-sm') ?>Mostre para o cliente pagar</p>
        <strong class="pix-valor"><?= $e($r((int) $pix['total'])) ?></strong>
        <?php if ((int) $pix['taxa'] > 0): ?>
            <p class="pix-taxa">Conta <?= $e($r((int) $pix['conta'])) ?> + <?= $e((string) ($pix['rotulo'] ?? 'Taxa do Pix')) ?> <?= $e($r((int) $pix['taxa'])) ?></p>
        <?php endif; ?>
        <div class="pix-qr" data-qr="<?= $e((string) $pix['copia_cola']) ?>" role="img" aria-label="QR Code do Pix de <?= $e($r((int) $pix['total'])) ?>">
            <noscript><p>Ative o JavaScript para ver o QR Code. Use o código abaixo.</p></noscript>
        </div>
        <details class="pix-codigo">
            <summary><?= Icon::svg('copy', 'icon-sm') ?>Código copia e cola</summary>
            <p><code data-pix-codigo><?= $e((string) $pix['copia_cola']) ?></code></p>
            <button type="button" class="btn btn-outline btn-sm" data-copiar="<?= $e((string) $pix['copia_cola']) ?>"><?= Icon::svg('copy', 'icon-sm') ?><span data-copiar-texto>Copiar código</span></button>
        </details>
        <p class="notice notice-warning pix-conferir"><?= Icon::svg('circle-exclamation-point') ?><span>Este Pix não confirma sozinho. Confira no app do banco do restaurante se o valor caiu antes de tocar em "Recebi o Pix".</span></p>
    </section>

    <form method="post" action="<?= $e($url . '/pix-chave/recebido') ?>" class="pix-recebido">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="total_visto" value="<?= (int) $pix['total'] ?>">
        <button class="btn btn-wine btn-lg btn-block" type="submit"><?= Icon::svg('check') ?>Recebi o Pix · <?= $e($r((int) $pix['total'])) ?></button>
    </form>
    <a class="btn btn-outline btn-lg btn-block pix-trocar" href="<?= $e($url . '/conta') ?>"><?= Icon::svg('arrow-left-right') ?>Trocar forma de pagamento</a>
</div>
