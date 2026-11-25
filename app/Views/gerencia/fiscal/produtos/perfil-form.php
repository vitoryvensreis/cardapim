<?php
use Cardapim\Core\Icon;
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as Validar;

require __DIR__.'/_base.php';
$v=static fn(string $k):string=>$e($dados[$k] ?? '');
$crt=(string)($empresa['crt'] ?? '');
$cfops=Validar::cfops($crt); $situacoes=Validar::situacoes($crt);
$selecionadoCfop=(string)($dados['cfop'] ?? '');
$selecionadoIcms=(string)($dados['situacao_icms'] ?? '');
if ($crt==='4' && $selecionadoCfop==='' && empty($rascunho)) { $selecionadoCfop='5102'; }
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1><?= $perfil?'Editar perfil tributário':'Novo perfil tributário' ?></h1><p>Preencha com a orientação do responsável fiscal. Alterações geram uma versão nova.</p></div></header>
    <p><a href="<?= $e($url.'/perfis') ?>">Voltar aos perfis</a></p>
    <section class="bloco"><p>Regime (CRT): <strong><?= $e($crt ?: 'Não cadastrado') ?></strong> · UF: <strong><?= $e($empresa['estado'] ?? 'Não cadastrada') ?></strong> · NFC-e (65)</p>
        <?php if (!empty($rascunho)): ?><p role="status">Os dados digitados foram recuperados. Corrija os campos e confirme novamente a revisão.</p><?php endif; ?>
        <form method="post" action="<?= $e($url.'/perfis/salvar') ?>" class="fiscal-form" data-fiscal-perfil><?php $token(); ?>
            <input type="hidden" name="id_perfil" value="<?= $e($perfil['id'] ?? '') ?>"><input type="hidden" name="versao" value="<?= $e($perfil['versao'] ?? '') ?>">
            <label for="fp-nome">Nome do perfil</label><input id="fp-nome" name="nome" required maxlength="120" value="<?= $v('nome') ?>">
            <label for="fp-op">Tipo de operação</label><select id="fp-op" name="operacao" required><option value="">Selecione</option><option value="producao" <?= ($dados['operacao'] ?? '')==='producao'?'selected':'' ?>>Produção própria</option><option value="revenda" <?= ($dados['operacao'] ?? '')==='revenda'?'selected':'' ?>>Revenda</option></select>
            <label for="fp-cfop">CFOP de saída interna</label>
            <?php if ($crt!=='4'): ?><label for="fp-cfop-busca">Buscar CFOP por código ou descrição</label><input id="fp-cfop-busca" type="search" data-fiscal-filtrar="fp-cfop"><?php endif; ?>
            <select id="fp-cfop" name="cfop"><option value="">Selecione</option><?php if ($selecionadoCfop!=='' && !isset($cfops[$selecionadoCfop])): ?><option value="<?= $e($selecionadoCfop) ?>" selected><?= $e($selecionadoCfop) ?> — código anterior incompatível, revise</option><?php endif; ?><?php foreach ($cfops as $k=>$rot): ?><option value="<?= $e($k) ?>" <?= (string)$k===$selecionadoCfop?'selected':'' ?>><?= $e($k.' — '.$rot) ?></option><?php endforeach; ?></select>
            <?php if ($crt==='4'): ?><p class="texto-suave">NFC-e de venda interna do MEI: CFOP 5102, inclusive para produção própria (MOC N12a-91).</p><?php endif; ?>
            <label for="fp-icms"><?= in_array($crt,['1','4'],true)?'CSOSN':'CST do ICMS' ?></label>
            <?php if ($crt!=='4'): ?><label for="fp-icms-busca">Buscar situação tributária por código ou descrição</label><input id="fp-icms-busca" type="search" data-fiscal-filtrar="fp-icms"><?php endif; ?>
            <select id="fp-icms" name="situacao_icms"><option value="">Selecione</option><?php if ($selecionadoIcms!=='' && !isset($situacoes[$selecionadoIcms])): ?><option value="<?= $e($selecionadoIcms) ?>" selected><?= $e($selecionadoIcms) ?> — código anterior incompatível, revise</option><?php endif; ?><?php foreach ($situacoes as $k=>$rot): ?><option value="<?= $e($k) ?>" <?= (string)$k===$selecionadoIcms?'selected':'' ?>><?= $e($k.' — '.$rot) ?></option><?php endforeach; ?></select>
            <p class="texto-suave">Escolha conforme orientação fiscal. A emissão ainda não está liberada.</p>
            <label for="fp-inicio">Vigência inicial</label><input id="fp-inicio" type="date" name="inicio" value="<?= $v('inicio') ?>">
            <details<?= !empty($rascunho)?' open':'' ?>><summary>Tributação avançada (quando aplicável)</summary><div class="fiscal-form">
                <?php foreach (['cst_pis'=>'CST PIS','cst_cofins'=>'CST COFINS','cbenef'=>'Código de benefício fiscal','cst_ibs_cbs'=>'CST IBS/CBS','cclass_trib'=>'Classificação IBS/CBS (cClassTrib)','cst_is'=>'CST Imposto Seletivo','cclass_is'=>'Classificação Imposto Seletivo'] as $k=>$rot): ?>
                    <label for="fp-<?= $e($k) ?>"><?= $e($rot) ?></label><input id="fp-<?= $e($k) ?>" name="<?= $e($k) ?>" maxlength="10" value="<?= $v($k) ?>">
                <?php endforeach; ?>
                <?php foreach (Validar::ALIQUOTAS as $k): if ($crt==='4' && in_array($k,['icms','st','fcp','reducao_icms'],true) && ($dados['aliq_'.$k] ?? '')==='') { continue; } ?><label for="fp-aliq-<?= $e($k) ?>"><?= $e(strtoupper(str_replace('_',' ',$k))) ?> (%)</label><input id="fp-aliq-<?= $e($k) ?>" name="aliq_<?= $e($k) ?>" inputmode="decimal" maxlength="10" value="<?= $v('aliq_'.$k) ?>"<?= in_array($k,['icms','st','fcp','reducao_icms'],true)?' data-fiscal-aliquota="'.$e($k).'"':'' ?>><?php endforeach; ?>
                <p class="texto-suave">Deixe em branco quando não aplicável. A validação completa dos grupos XML será feita antes da emissão.</p>
            </div></details>
            <label for="fp-ref">Referência da orientação fiscal</label><input id="fp-ref" name="referencia" maxlength="250" value="<?= $v('referencia') ?>" placeholder="Ex.: orientação do contador em 25/11">
            <label class="fiscal-check"><input type="checkbox" name="revisado" value="1"> Confirmo que o responsável fiscal revisou esta versão para o regime, UF e operação indicados.</label>
            <?php if ($perfil): ?><p>Versão atual: <?= $e($perfil['versao']) ?>. <?= $e($perfil['vinculados']) ?> produtos vinculados. Os vínculos anteriores não serão atualizados automaticamente.</p><label class="fiscal-check"><input type="checkbox" name="confirmar_impacto" value="1" required> Confirmo a nova versão e a necessidade de revisar os vínculos.</label><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?> Salvar perfil</button>
        </form>
    </section>
</div>
