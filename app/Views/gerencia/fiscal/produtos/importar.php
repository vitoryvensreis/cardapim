<?php
require __DIR__.'/_base.php';
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Importar classificação fiscal</h1><p>Aproveite dados de notas de fornecedores ou planilhas sem movimentar o estoque.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Voltar aos produtos fiscais</a></header>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Escolha um arquivo</h2></div>
        <p>XML processado de produção (NF-e/NFC-e com o CNPJ do restaurante) ou CSV UTF-8 separado por ponto e vírgula. Até 2 MB e 200 itens. Os dados serão apresentados para revisão; não verificamos a autenticidade do XML na SEFAZ.</p>
        <form method="post" enctype="multipart/form-data" action="<?= $e($url.'/produtos/importar/ler') ?>" class="fiscal-form"><?php $token(); ?>
            <label for="importacao-tipo">Tipo do arquivo</label><select id="importacao-tipo" name="tipo"><option value="xml">XML da nota fiscal</option><option value="csv">CSV de classificação</option></select>
            <label for="importacao-arquivo">Arquivo</label><input id="importacao-arquivo" name="arquivo" type="file" accept=".xml,.csv" required>
            <button class="btn btn-primary" type="submit">Ler e revisar arquivo</button>
        </form>
        <p>Para CSV, use as colunas abaixo. Campos além dos fiscais permitidos são ignorados. Não preencha GTIN de produtos distintos com um mesmo código.</p>
        <pre class="fiscal-csv-modelo">nome;codigo;ncm;cest;origem;gtin;gtin_tributavel;unidade_comercial;unidade_tributavel;fator</pre>
        <p>CFOP, CST, alíquotas, preço, quantidade de estoque e dados do fornecedor não serão importados.</p>
    </section>
</div>
