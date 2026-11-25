<?php require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Relacionar mercadorias</h1><p>Selecione o produto existente para cada item que deseja importar. Nada será criado automaticamente.</p></div></header>
    <section class="bloco">
        <form method="post" action="<?= $e($url.'/produtos/importar/previa') ?>" class="fiscal-form"><?php $token(); ?><input type="hidden" name="importacao" value="<?= $e($importacao) ?>">
            <datalist id="fiscal-produtos-importacao"><?php foreach ($produtos as $p): ?><option value="<?= $e($p['id']) ?>"><?= $e($p['nome']) ?><?= $p['codigo']?' · '.$e($p['codigo']):'' ?></option><?php endforeach; ?></datalist>
            <?php foreach ($itens as $i=>$item): ?><div class="fiscal-linha"><div><strong><?= $e($item['nome']) ?></strong><small><?= $e($item['codigo']) ?> · <?= $e($item['dados']['ncm'] ?? 'NCM não informado') ?></small><?php foreach ($item['dados'] as $k=>$v): ?><small><?= $e($k.': '.$v) ?></small><?php endforeach; ?></div><label>Produto existente (ID, busque pelo nome)
                <input list="fiscal-produtos-importacao" name="destinos[<?= $i ?>]" value="<?= $e($item['sugestao'] ?? '') ?>" inputmode="numeric" placeholder="Deixe vazio para ignorar"></label></div><?php endforeach; ?>
            <label class="fiscal-check"><input type="checkbox" name="sobrescrever" value="1"> Substituir campos já preenchidos. Desmarcado, apenas campos vazios serão preenchidos.</label>
            <button class="btn btn-primary" type="submit">Revisar alterações</button>
        </form>
        <p><a href="<?= $e($url.'/produtos/importar') ?>">Cancelar</a></p>
    </section>
</div>
