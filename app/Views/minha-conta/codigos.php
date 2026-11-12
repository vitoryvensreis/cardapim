<?php
/** Codigos de recuperacao recem-gerados (aparecem uma unica vez). Espera: $codigos, $titulo, $base, $restaurante. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1><?= $e($titulo) ?></h1>
            <p>Guarde estes códigos. Eles servem para entrar se você perder o celular ou não conseguir receber o e-mail.</p>
        </div>
    </header>

    <div class="notice notice-warning erros-form" role="alert">
        <?= Icon::svg('triangle-exclamation-point') ?>
        <div><strong>Eles aparecem só agora.</strong><p>Cada código funciona uma única vez. Imprima, salve em um lugar seguro ou anote em papel.</p></div>
    </div>

    <section class="bloco">
        <ul class="codigos-recuperacao" data-codigos>
            <?php foreach ($codigos as $codigo): ?><li><?= $e($codigo) ?></li><?php endforeach; ?>
        </ul>
        <div class="acoes-2fa">
            <button class="btn btn-outline btn-sm" type="button" data-copiar="<?= $e(implode("\n", $codigos)) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar códigos</button>
            <button class="btn btn-outline btn-sm" type="button" data-baixar-codigos data-arquivo="codigos-cardapim.txt"><?= Icon::svg('file-text', 'icon-sm') ?>Baixar arquivo</button>
            <button class="btn btn-outline btn-sm" type="button" data-imprimir><?= Icon::svg('receipt', 'icon-sm') ?>Imprimir</button>
            <span class="texto-suave" data-copiado role="status"></span>
        </div>
        <div class="bloco-rodape">
            <a class="btn btn-primary" href="<?= $e($base . '/minha-conta#duas-etapas') ?>"><?= Icon::svg('check', 'icon-sm') ?>Já guardei os códigos</a>
        </div>
    </section>
</div>
