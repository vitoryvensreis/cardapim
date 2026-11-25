<?php require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Confirmar importação fiscal</h1><p>Confira os dados de cada mercadoria. Classificações alteradas ficarão pendentes de revisão.</p></div></header>
    <section class="bloco">
        <?php foreach ($previa as $p): ?><div class="fiscal-linha"><div><strong><?= $e($p['nome']) ?></strong><small>Referência: <?= $e($p['origem_nome']) ?></small></div><div><?php foreach ($p['mudancas'] as $k=>$m): ?><small><?= $e($k) ?>: <?= $e($m['de']===''?'(vazio)':$m['de']) ?> → <?= $e($m['para']) ?></small><?php endforeach; ?></div></div><?php endforeach; ?>
        <p><?= count($previa) ?> produtos serão atualizados. Perfis e dados comerciais permanecem intactos.</p>
        <form method="post" action="<?= $e($url.'/produtos/importar/aplicar') ?>"><?php $token(); ?><input type="hidden" name="importacao" value="<?= $e($importacao) ?>"><button class="btn btn-primary" type="submit">Confirmar importação</button> <a class="btn btn-outline" href="<?= $e($url.'/produtos/importar') ?>">Cancelar</a></form>
    </section>
</div>
