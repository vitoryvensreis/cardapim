<?php
use Cardapim\Core\Icon;
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;

require __DIR__.'/_base.php';
$d=$produto['dados'];
$sugerido=!empty($rascunho)?$produto['id_perfil']:($produto['id_perfil'] ?? $produto['sugestao']);
$un=strtoupper((string)($produto['unidade_venda'] ?? 'UN'));
$v=static fn(string $k,string $padrao=''):string=>$e($d[$k] ?? $padrao);
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Dados fiscais: <?= $e($produto['nome']) ?></h1><p>Esta configuração é independente do cadastro comercial.</p></div></header>
    <p><a href="<?= $e($url.'/produtos') ?>">Voltar aos produtos fiscais</a> · <a href="<?= $e($base.'/estoque/produtos/'.$produto['id']) ?>">Editar cadastro comercial</a></p>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Reaproveitar classificação</h2><p>Use um produto semelhante como ponto de partida. Os códigos de barras não serão copiados.</p></div>
        <form method="get" action="<?= $e($url.'/produtos/'.$produto['id']) ?>" class="fiscal-form"><label for="pf-copiar">Produto de referência</label><select id="pf-copiar" name="copiar" required><option value="">Selecione</option><?php foreach ($produtos as $pr): if ((int)$pr['id']===(int)$produto['id'] || empty($pr['dados']['ncm'])) { continue; } ?><option value="<?= $e($pr['id']) ?>"><?= $e($pr['nome']) ?></option><?php endforeach; ?></select><button class="btn btn-outline" type="submit">Carregar para revisão</button></form>
        <?php if ($copiaNome!==null): ?><p role="status">Dados de <?= $e($copiaNome) ?> carregados como sugestão. Nenhuma alteração foi salva.</p><?php endif; ?>
    </section>
    <section class="bloco"><h2><?= $produto['pendencias']===[]?'Cadastro revisado':'Pendências do cadastro' ?></h2><?php foreach ($produto['pendencias'] as $p): ?><p class="texto-suave"><?= $e($p) ?></p><?php endforeach; ?></section>
    <section class="bloco">
        <?php if (!empty($rascunho)): ?><p role="status">Os dados digitados foram recuperados. Corrija os campos e confirme novamente.</p><?php endif; ?>
        <form method="post" action="<?= $e($url.'/produtos/'.$produto['id']) ?>" class="fiscal-form" data-fiscal-classificacao data-ncm-url="<?= $e($url.'/classificacoes/ncm') ?>" data-gtin-url="<?= $e($url.'/classificacoes/gtin') ?>" data-cest-url="<?= $e($url.'/produtos/catalogos') ?>"><?php $token(); ?>
            <label for="pf-perfil">Perfil tributário</label><select id="pf-perfil" name="id_perfil"><option value="">Sem perfil (pendente)</option><?php if ($sugerido!==null && (!isset($perfis[$sugerido]) || !Validar::perfilVigente($perfis[$sugerido],$empresa))): ?><option value="<?= $e($sugerido) ?>" selected>Perfil anterior incompatível ou não revisado — selecione novamente</option><?php endif; ?><?php foreach ($perfis as $p): if (!Validar::perfilVigente($p,$empresa)) { continue; } ?><option value="<?= $e($p['id']) ?>" <?= (int)$sugerido===(int)$p['id']?'selected':'' ?>><?= $e($p['nome']) ?> · v<?= $e($p['versao']) ?></option><?php endforeach; ?></select>
            <?php if ($produto['id_perfil']===null && $produto['sugestao']!==null): ?><p class="texto-suave">Sugestão da categoria. Confira antes de salvar.</p><?php endif; ?>
            <label for="pf-pesquisa">Pesquisar NCM pela BrasilAPI</label><input id="pf-pesquisa" data-ncm-busca type="search" maxlength="100" placeholder="Ex.: refrigerante, 2202" value="<?= $e(mb_substr((string)$produto['nome'],0,100)) ?>"><button class="btn btn-outline" type="button" data-ncm-pesquisar><?= Icon::svg('search','icon-sm') ?> Pesquisar classificação</button>
            <p role="status" aria-live="polite" data-ncm-status></p><div data-ncm-resultados class="fiscal-lista"></div>
            <label for="pf-ncm">NCM (8 dígitos)</label><input id="pf-ncm" name="ncm" inputmode="numeric" maxlength="8" value="<?= $v('ncm') ?>">
            <label for="pf-cest">CEST (quando aplicável)</label><input id="pf-cest" name="cest" inputmode="numeric" maxlength="7" value="<?= $v('cest') ?>">
            <label for="pf-cest-busca">Buscar CEST por descrição ou código</label><input id="pf-cest-busca" type="search" data-cest-busca placeholder="Filtrar pelo NCM selecionado">
            <button class="btn btn-outline" type="button" data-cest-pesquisar><?= Icon::svg('search','icon-sm') ?> Pesquisar CEST compatíveis</button>
            <p role="status" aria-live="polite" data-cest-status></p><div data-cest-resultados class="fiscal-lista"></div>
            <p class="texto-suave">Confirme descrição e segmento. Deixe vazio somente se não se aplicar; NCM sozinho não determina ST.</p>
            <label for="pf-origem">Origem da mercadoria (0 a 8)</label><select id="pf-origem" name="origem"><option value="">Selecione com o responsável fiscal</option><?php foreach (['0'=>'Nacional (regra geral)','1'=>'Estrangeira, importação direta','2'=>'Estrangeira, adquirida no mercado interno','3'=>'Nacional, conteúdo de importação superior a 40% e até 70%','4'=>'Nacional, processos produtivos básicos','5'=>'Nacional, conteúdo de importação até 40%','6'=>'Estrangeira, importação direta sem similar nacional','7'=>'Estrangeira, mercado interno sem similar nacional','8'=>'Nacional, conteúdo de importação superior a 70%'] as $k=>$r): ?><option value="<?= $e($k) ?>" <?= (string)($d['origem'] ?? '')===(string)$k?'selected':'' ?>><?= $e($k.' — '.$r) ?></option><?php endforeach; ?></select>
            <label for="pf-gtin">GTIN comercial (código de barras ou SEM GTIN)</label><input id="pf-gtin" name="gtin" maxlength="14" value="<?= $v('gtin') ?>">
            <?php if (Validar::gtin((string)($produto['codigo'] ?? ''))): ?><button class="btn btn-outline" type="button" data-fiscal-gtin="<?= $e($produto['codigo']) ?>">Usar código existente após conferir</button><?php endif; ?>
            <button class="btn btn-outline" type="button" data-fiscal-gtin-consultar><?= Icon::svg('search','icon-sm') ?> Consultar código de barras pela NeXT</button>
            <p class="texto-suave">Consulta em homologação; requer certificado A1 válido. Nenhum dado será salvo automaticamente.</p>
            <p data-fiscal-gtin-status role="status" aria-live="polite"></p><div data-fiscal-gtin-resultado></div>
            <button type="button" class="btn btn-outline" data-fiscal-sem-gtin>Produto sem código de barras</button>
            <details<?= !empty($rascunho)?' open':'' ?>><summary>Unidades, conversão e código tributável</summary><div class="fiscal-form">
            <label for="pf-gtint">GTIN tributável (ou SEM GTIN)</label><input id="pf-gtint" name="gtin_tributavel" maxlength="14" value="<?= $v('gtin_tributavel') ?>">
            <label for="pf-un">Unidade comercial</label><input id="pf-un" name="unidade_comercial" maxlength="6" value="<?= $v('unidade_comercial',$un) ?>">
            <label for="pf-unt">Unidade tributável</label><input id="pf-unt" name="unidade_tributavel" maxlength="6" value="<?= $v('unidade_tributavel',(string)($d['unidade_comercial'] ?? $un)) ?>">
            <label for="pf-fator">Fator: quantidade tributável por unidade comercial</label><input id="pf-fator" name="fator" maxlength="18" inputmode="decimal" value="<?= $v('fator',empty($d['unidade_tributavel']) || ($d['unidade_tributavel']===($d['unidade_comercial'] ?? $un))?'1':'') ?>">
            <button type="button" class="btn btn-outline" data-fiscal-unidades-iguais>Usar unidades iguais e fator 1</button>
            <p class="texto-suave">Confira as unidades e o fator. Não alteram venda por peso nem estoque.</p></div></details>
            <label class="fiscal-check"><input type="checkbox" name="confirmado" value="1"> Conferi a classificação individual, unidades, origem, CEST e GTIN com a orientação fiscal.</label>
            <button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?> Salvar dados fiscais</button>
        </form>
    </section>
</div>
