<?php
use Cardapim\Core\Icon;
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;
 require __DIR__.'/_base.php'; ?>
<?php $rotulosFiscais=['ncm'=>'NCM','cest'=>'CEST','origem'=>'Origem','gtin'=>'GTIN','gtin_tributavel'=>'GTIN tributável','unidade_comercial'=>'Unidade comercial','unidade_tributavel'=>'Unidade tributável','fator'=>'Fator de conversão']; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Revisar aplicação em massa</h1><p><?= $e($previa['nome']) ?><?= $previa['versao']!==null?' · versão '.$e($previa['versao']):'' ?>. Nenhuma alteração foi gravada.</p></div></header>
    <section class="bloco">
        <?php foreach ($previa['itens'] as $p): ?><div class="fiscal-linha"><strong><?= $e($p['nome']) ?></strong><span><?= $e($p['anterior']) ?> → <?= $e($p['novo']) ?></span><?php foreach ($p['mudancas'] as $k=>$m): ?><small><?= $e($rotulosFiscais[$k] ?? $k) ?>: <?= $e($m['de']===''?'(vazio)':$m['de']) ?> → <?= $e($m['para']) ?></small><?php endforeach; ?></div><?php endforeach; ?>
        <?php if (!empty($previa['aviso_ncm'])): ?><p class="fiscal-aviso"><strong>O NCM informado ainda não foi conferido na BrasilAPI.</strong> Os produtos ficarão com essa pendência. Pesquise o código na tela anterior antes de aplicar, ou use "Revalidar NCMs" depois.</p><?php endif; ?>
        <p>Serão processados <?= count($previa['itens']) ?> produtos. Somente as alterações apresentadas serão gravadas. GTIN permanece intacto; classificações alteradas exigirão nova revisão individual.</p>
        <form method="post" action="<?= $e($url.'/produtos/aplicar') ?>"><?php $token(); ?><input type="hidden" name="lote" value="<?= $e($lote) ?>"><button class="btn btn-primary" type="submit">Confirmar aplicação</button> <a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Cancelar</a></form>
    </section>
</div>
