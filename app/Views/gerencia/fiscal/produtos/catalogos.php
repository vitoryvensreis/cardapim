<?php
use Cardapim\Modules\Fiscal\CatalogosFiscais;
require __DIR__.'/_base.php';
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Pesquisar tabelas fiscais</h1><p>Consulte os códigos das versões disponibilizadas pelo administrador. Confirme a aplicação ao produto com o responsável fiscal.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Voltar aos produtos</a></header>
    <section class="bloco">
        <form class="fiscal-form" method="get" action="<?= $e($url.'/produtos/catalogos') ?>">
            <label for="cat-tipo">Tabela</label><select id="cat-tipo" name="tipo"><?php foreach (CatalogosFiscais::TIPOS as $k=>$v): ?><option value="<?= $e($k) ?>" <?= $tipo===$k?'selected':'' ?>><?= $e($v[0]) ?></option><?php endforeach; ?></select>
            <label for="cat-q">Código ou descrição</label><input id="cat-q" name="q" maxlength="100" value="<?= $e($q) ?>">
            <label for="cat-ncm">NCM para filtrar CEST (opcional)</label><input id="cat-ncm" name="ncm" inputmode="numeric" maxlength="8" value="<?= $e($ncm) ?>">
            <label for="cat-cst">CST para filtrar cClassTrib (opcional)</label><input id="cat-cst" name="cst" inputmode="numeric" maxlength="3" value="<?= $e($cst) ?>">
            <button type="submit" class="btn btn-primary">Pesquisar</button>
        </form>
    </section>
    <section class="bloco"><h2>Resultados</h2>
        <p><a href="<?= $e(CatalogosFiscais::TIPOS[$tipo][2]) ?>" target="_blank" rel="noopener noreferrer">Consultar fonte oficial</a></p>
        <?php if (!$catalogo): ?><p>Catálogo ainda não disponibilizado pela administração. Consulte a fonte oficial.</p><?php else: ?>
            <p>Versão declarada: <?= $e($catalogo['versao']) ?> · Vigência: <?= $e($catalogo['vigencia']) ?>. A busca apresenta até 35 candidatos; não determina tributação.</p>
            <?php foreach ($resultados as $r): ?><div class="fiscal-linha"><strong><?= $e($r['codigo']) ?></strong><span><?= $e($r['descricao']) ?></span><?php if ($r['ncm']!==''): ?><small>NCM <?= $e($r['ncm']) ?></small><?php endif; ?><?php if ($r['cst']!==''): ?><small>CST <?= $e($r['cst']) ?></small><?php endif; ?></div><?php endforeach; ?>
            <?php if (!$resultados): ?><p>Nenhum candidato. Confira os filtros.</p><?php endif; ?>
        <?php endif; ?>
    </section>
</div>
