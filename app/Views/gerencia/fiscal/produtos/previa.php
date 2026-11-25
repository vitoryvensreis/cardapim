<?php
use Cardapim\Core\Icon;
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;
 require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Revisar aplicação em massa</h1><p>Perfil <?= $e($previa['nome']) ?> · versão <?= $e($previa['versao']) ?>. Nenhuma alteração foi gravada.</p></div></header>
    <section class="bloco">
        <?php foreach ($previa['itens'] as $p): ?><div class="fiscal-linha"><strong><?= $e($p['nome']) ?></strong><span><?= $e($p['anterior']) ?> → <?= $e($previa['nome']) ?></span></div><?php endforeach; ?>
        <p>Serão vinculados <?= count($previa['itens']) ?> produtos. NCM, CEST, GTIN, origem e demais dados individuais permanecem intactos.</p>
        <form method="post" action="<?= $e($url.'/produtos/aplicar') ?>"><?php $token(); ?><input type="hidden" name="lote" value="<?= $e($lote) ?>"><button class="btn btn-primary" type="submit">Confirmar aplicação</button> <a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Cancelar</a></form>
    </section>
</div>
