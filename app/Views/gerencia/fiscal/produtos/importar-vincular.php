<?php require __DIR__.'/_base.php'; ?>
<?php $rotulosFiscais=['ncm'=>'NCM','cest'=>'CEST','origem'=>'Origem','gtin'=>'GTIN','gtin_tributavel'=>'GTIN tributável','unidade_comercial'=>'Unidade comercial','unidade_tributavel'=>'Unidade tributável','fator'=>'Fator de conversão']; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Relacionar mercadorias</h1><p>Para cada item do arquivo, escolha o produto do cardápio que vai receber a classificação. Nada é criado automaticamente.</p></div></header>
    <section class="bloco">
        <form method="post" action="<?= $e($url.'/produtos/importar/previa') ?>" class="fiscal-form"><?php $token(); ?><input type="hidden" name="importacao" value="<?= $e($importacao) ?>">
            <?php foreach ($itens as $i=>$item): $escolhido=(string)($destinos[$i] ?? ($item['sugestao'] ?? '')); ?><div class="fiscal-linha"><div><strong><?= $e($item['nome']) ?></strong><?php if ($item['codigo']!==''): ?><small>Código no arquivo: <?= $e($item['codigo']) ?></small><?php endif; ?>
                    <?php foreach ($item['dados'] as $k=>$v): ?><small><?= $e(($rotulosFiscais[$k] ?? $k).': '.$v) ?></small><?php endforeach; ?></div>
                <label class="fiscal-vinculo">Produto do cardápio
                    <select name="destinos[<?= $i ?>]"><option value="">Ignorar este item</option><?php foreach ($produtos as $p): ?><option value="<?= $e($p['id']) ?>" <?= (string)$p['id']===$escolhido?'selected':'' ?>><?= $e($p['nome']) ?><?= $p['codigo']?' · '.$e($p['codigo']):'' ?></option><?php endforeach; ?></select>
                </label><?php if (!empty($item['sugestao']) && (string)$item['sugestao']===$escolhido): ?><small>Sugerido pelo código de barras</small><?php endif; ?></div><?php endforeach; ?>
            <label class="fiscal-check"><input type="checkbox" name="sobrescrever" value="1" <?= !empty($substituir)?'checked':'' ?>> Substituir campos já preenchidos. Desmarcado, só os campos vazios são preenchidos.</label>
            <button class="btn btn-primary" type="submit">Revisar alterações</button>
        </form>
        <p><a class="btn btn-outline" href="<?= $e($url.'/produtos/importar') ?>">Cancelar</a></p>
    </section>
</div>
