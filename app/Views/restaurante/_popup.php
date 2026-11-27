<?php
/**
 * Popup do cardapio (Gerencia > Clientes > Popup do cardapio): banner 1:1 ou texto. Abre uma vez por visita
 * (cardapio-popup.js guarda a versao na aba do navegador); fecha pelo X, por "Ver cardapio", Esc ou toque fora.
 * Espera: $popup (PopupService::paraCardapio), $restaurante, $e.
 */
use Cardapim\Core\Icon;

$tituloPopup = trim((string) $popup['titulo']);
?>
<dialog class="cd-popup" data-popup data-popup-chave="<?= $e($restaurante['slug'] . ':' . (int) $popup['versao']) ?>"
        <?= $popup['tipo'] === 'texto' ? 'aria-labelledby="cd-popup-titulo"' : 'aria-label="' . $e($tituloPopup !== '' ? $tituloPopup : 'Aviso de ' . $restaurante['nome']) . '"' ?>>
    <div class="cd-popup-caixa">
        <button class="cd-popup-fechar" type="button" aria-label="Fechar" data-popup-fechar><?= Icon::svg('x') ?></button>
        <?php if ($popup['tipo'] === 'imagem' && $popup['imagem'] !== null): ?>
            <img class="cd-popup-imagem" src="<?= $e($popup['imagem']) ?>" alt="<?= $e($tituloPopup !== '' ? $tituloPopup : 'Aviso de ' . $restaurante['nome']) ?>" width="1080" height="1080" decoding="async">
        <?php else: ?>
            <div class="cd-popup-texto">
                <h2 id="cd-popup-titulo"><?= $e($tituloPopup) ?></h2>
                <p><?= nl2br($e((string) $popup['texto'])) ?></p>
            </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-block cd-popup-ok" type="button" data-popup-fechar><?= Icon::svg('book-open', 'icon-sm') ?>Ver cardápio</button>
    </div>
</dialog>
