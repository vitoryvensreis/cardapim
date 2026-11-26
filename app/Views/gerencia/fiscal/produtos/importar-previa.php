<?php require __DIR__.'/_base.php'; ?>
<?php $rotulosFiscais=['ncm'=>'NCM','cest'=>'CEST','origem'=>'Origem','gtin'=>'GTIN','gtin_tributavel'=>'GTIN tributável','unidade_comercial'=>'Unidade comercial','unidade_tributavel'=>'Unidade tributável','fator'=>'Fator de conversão']; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Confirmar importação fiscal</h1><p>Confira os dados de cada mercadoria. Classificações alteradas ficarão pendentes de revisão.</p></div></header>
    <section class="bloco">
        <?php foreach ($previa as $p): ?><div class="fiscal-linha"><div><strong><?= $e($p['nome']) ?></strong><small>Referência: <?= $e($p['origem_nome']) ?></small></div><div class="fiscal-mudancas"><?php foreach ($p['mudancas'] as $k=>$m): ?><small><?= $e($rotulosFiscais[$k] ?? $k) ?>: <?= $e($m['de']===''?'(vazio)':$m['de']) ?> → <?= $e($m['para']) ?></small><?php endforeach; ?><?php if (isset($p['mudancas']['unidade_comercial'])): ?><small class="fiscal-erro-campo">A unidade veio da nota do fornecedor. Confira se é a mesma unidade em que você vende.</small><?php endif; ?></div></div><?php endforeach; ?>
        <p><?= count($previa) ?> produtos serão atualizados. Perfis e dados comerciais permanecem intactos.</p>
        <form method="post" action="<?= $e($url.'/produtos/importar/aplicar') ?>"><?php $token(); ?><input type="hidden" name="importacao" value="<?= $e($importacao) ?>"><button class="btn btn-primary" type="submit">Confirmar importação</button> <a class="btn btn-outline" href="<?= $e($url.'/produtos/importar') ?>">Cancelar</a></form>
    </section>
</div>
