<?php
use Cardapim\Modules\Fiscal\CatalogosFiscais;
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<div class="painel-page fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Catálogos fiscais</h1><p>Importe cópias normalizadas das tabelas oficiais. As versões anteriores ficam preservadas.</p></div><a class="btn btn-outline" href="<?= $e($base.'/fiscal') ?>">Voltar à Central Fiscal</a></header>
    <section class="bloco"><h2>Fontes de referência</h2>
        <p><a href="<?= $e(CatalogosFiscais::PORTAL) ?>" target="_blank" rel="noopener noreferrer">Portal Nacional da NF-e — CFOP, CST e cClassTrib</a></p>
        <p><a href="<?= $e(CatalogosFiscais::CONFAZ) ?>" target="_blank" rel="noopener noreferrer">CONFAZ — Convênio ICMS 142/18 (CEST)</a></p>
        <p><a href="<?= $e(CatalogosFiscais::MOC) ?>" target="_blank" rel="noopener noreferrer">Manual de Orientação do Contribuinte</a></p>
        <p>Baixe a publicação vigente, normalize para CSV UTF-8 com separador ponto e vírgula. Não há sincronização automática nem certificação do conteúdo enviado. Confira a versão com o responsável fiscal.</p>
        <pre class="fiscal-csv-modelo">codigo;descricao;ncm;cst;nfce</pre>
        <p>CEST: um prefixo NCM por linha. cClassTrib: CST e indicador NFC-e sim/nao conforme a fonte. Outras tabelas: código e descrição. Preserve zeros à esquerda. Até 10.000 linhas e 2 MB.</p>
    </section>
    <section class="bloco"><h2>Registrar uma versão</h2>
        <form class="fiscal-form" method="post" enctype="multipart/form-data" action="<?= $e($base.'/fiscal/catalogos/importar') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <label for="cat-tipo">Tabela</label><select id="cat-tipo" name="tipo"><?php foreach (CatalogosFiscais::TIPOS as $k=>$v): ?><option value="<?= $e($k) ?>"><?= $e($v[0]) ?></option><?php endforeach; ?></select>
            <label for="cat-versao">Versão da publicação</label><input id="cat-versao" name="versao" maxlength="100" required>
            <label for="cat-vigencia">Data de vigência</label><input id="cat-vigencia" name="vigencia" type="date" required>
            <label for="cat-arquivo">CSV normalizado</label><input id="cat-arquivo" name="arquivo" type="file" accept=".csv" required>
            <label class="fiscal-check"><input type="checkbox" name="confirmacao" value="1" required> Conferi a fonte oficial, a versão, a vigência e a normalização dos dados.</label>
            <button class="btn btn-primary" type="submit">Importar versão</button>
        </form>
    </section>
    <section class="bloco"><h2>Versões registradas</h2><?php if (!$catalogos): ?><p>Nenhuma versão registrada.</p><?php endif; ?><?php foreach ($catalogos as $c): ?><div class="fiscal-linha"><strong><?= $e(CatalogosFiscais::TIPOS[$c['tipo']][0] ?? $c['tipo']) ?></strong><span><?= $e($c['versao']) ?> · <?= $e($c['vigencia']) ?></span><small><?= $e(substr($c['sha256'],0,12)) ?> · <?= $e($c['criado_em']) ?></small></div><?php endforeach; ?></section>
</div>
