<?php
/**
 * Pix por QR Code na entrega (motoboy): o cliente le o QR do Asaas do restaurante. A tela consulta a cada 4 s e,
 * quando o Pix cai, volta para as entregas com o "Entreguei" liberado (sem cobrar de novo).
 * Espera: $pedido, $restaurante, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$volta = '/' . rawurlencode((string) $restaurante['slug']) . '/entregas';
$p = $pedido;
?>
<div class="entregador entrega-pix" data-pix-entrega="<?= $e($volta . '/' . (int) $p['id'] . '/pix') ?>">
    <section class="pix-entrega-cartao" data-pix-esperando>
        <p class="pix-entrega-titulo"><?= Icon::svg('smartphone', 'icon-sm') ?>Pedido #<?= (int) $p['numero'] ?> · mostre para o cliente pagar</p>
        <strong class="pix-entrega-valor"><?= $e(Numero::reais((string) $p['total'])) ?></strong>
        <?php if (Numero::centavos((string) ($p['taxa_pagamento'] ?? '0')) > 0): ?>
            <p class="pix-entrega-taxa">Inclui <?= $e((string) ($p['taxa_pagamento_rotulo'] ?: 'taxa do Pix')) ?>: <?= $e(Numero::reais((string) $p['taxa_pagamento'])) ?></p>
        <?php endif; ?>
        <div class="pix-entrega-qr" data-qr="<?= $e((string) $p['pix_copia_cola']) ?>" role="img" aria-label="QR Code do Pix de <?= $e(Numero::reais((string) $p['total'])) ?>">
            <noscript><p>Ative o JavaScript para ver o QR Code. Use o código abaixo.</p></noscript>
        </div>
        <p class="pix-entrega-status" aria-live="polite"><span class="pix-girando" aria-hidden="true"></span>Esperando o pagamento</p>
        <details class="pix-entrega-codigo">
            <summary><?= Icon::svg('copy', 'icon-sm') ?>Código copia e cola</summary>
            <p><code><?= $e((string) $p['pix_copia_cola']) ?></code></p>
            <button type="button" class="btn btn-outline btn-sm" data-copiar="<?= $e((string) $p['pix_copia_cola']) ?>"><?= Icon::svg('copy', 'icon-sm') ?><span data-copiar-texto>Copiar código</span></button>
        </details>
        <p class="pix-entrega-validade"><?= Icon::svg('timer', 'icon-sm') ?>Vale até <?= $e(date('H:i', (int) strtotime((string) $p['pix_expira_em']))) ?>. Depois, gere de novo em "Trocar forma de pagamento".</p>
    </section>
    <section class="pix-entrega-pago" data-pix-pago hidden aria-live="assertive">
        <span class="pix-entrega-ok" aria-hidden="true"><?= Icon::svg('circle-check', 'icon-lg') ?></span>
        <h1>Pix recebido</h1>
        <p>Pedido #<?= (int) $p['numero'] ?> · <?= $e(Numero::reais((string) $p['total'])) ?>. Agora é só entregar e tocar em "Entreguei".</p>
    </section>
    <a class="btn btn-outline btn-lg btn-block" href="<?= $e($volta) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Voltar às entregas</a>
</div>
