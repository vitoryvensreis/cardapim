<?php
/**
 * QR Code do Pix para o cliente pagar na mesa. A tela consulta a situacao a cada 3 s e fecha sozinha quando paga.
 * Espera: $pix (comanda_pix), $c (detalhes da comanda), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="garcom-pagina garcom-pix" data-pix="<?= $e($base . '/pix/' . (int) $pix['id']) ?>">
    <section class="pix-cartao" data-pix-esperando>
        <p class="pix-instrucao"><?= Icon::svg('smartphone', 'icon-sm') ?>Mostre para o cliente pagar</p>
        <strong class="pix-valor"><?= $e(Numero::reais((string) $pix['valor'])) ?></strong>
        <div class="pix-qr" data-qr="<?= $e((string) $pix['copia_cola']) ?>" role="img" aria-label="QR Code do Pix de <?= $e(Numero::reais((string) $pix['valor'])) ?>">
            <noscript><p>Ative o JavaScript para ver o QR Code. Use o código abaixo.</p></noscript>
        </div>
        <div class="pix-status" aria-live="polite" data-pix-status>
            <span class="pix-girando" aria-hidden="true"></span>
            <span>Esperando o pagamento</span>
        </div>
        <details class="pix-codigo">
            <summary><?= Icon::svg('copy', 'icon-sm') ?>Código copia e cola</summary>
            <p><code data-pix-codigo><?= $e((string) $pix['copia_cola']) ?></code></p>
            <button type="button" class="btn btn-outline btn-sm" data-copiar="<?= $e((string) $pix['copia_cola']) ?>"><?= Icon::svg('copy', 'icon-sm') ?><span data-copiar-texto>Copiar código</span></button>
        </details>
        <p class="pix-validade"><?= Icon::svg('timer', 'icon-sm') ?>Vale até <?= $e(date('H:i', (int) strtotime((string) $pix['expira_em']))) ?></p>
    </section>

    <section class="pix-pago" data-pix-pago hidden aria-live="assertive">
        <span class="gc-paga-icone" aria-hidden="true"><?= Icon::svg('circle-check', 'icon-lg') ?></span>
        <h1>Pix recebido</h1>
        <p><?= $e(Numero::reais((string) $pix['valor'])) ?> · <?= $e((string) ($c['rotulo'] ?? '')) ?></p>
    </section>

    <form method="post" action="<?= $e($base . '/pix/' . (int) $pix['id'] . '/cancelar') ?>" class="pix-trocar" data-pix-trocar>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-outline btn-lg btn-block" type="submit"><?= Icon::svg('arrow-left-right') ?>Trocar forma de pagamento</button>
    </form>
</div>
