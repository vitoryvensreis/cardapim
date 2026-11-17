<?php
/** Cards operacionais exibidos na aba Status; contexto em _ifood-variaveis.php. */
?>
    <?= $aviso($p['erros']['geral'] ?? null) ?>
    <div class="ifood-painel-operacao" id="operacao-ifood">
    <?php require __DIR__ . '/_ifood-situacao.php'; ?>
    <section class="bloco" id="pausas" aria-labelledby="titulo-pausas" data-pausas data-pausas-status-url="<?= $e($base . '/integracoes/ifood/pausas/estado') ?>">
        <?php $pausas = $p['pausas']; $erroLista = $p['erros']['pausas'] ?? null; $nota = null; require __DIR__ . '/_ifood-pausas.php'; ?>
    </section>


    </div>
