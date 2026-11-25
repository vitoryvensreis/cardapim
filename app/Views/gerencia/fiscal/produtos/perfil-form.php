<?php
require __DIR__.'/_base.php';
$v=static fn(string $k):string=>$e($dados[$k] ?? '');
$crt=(string)($empresa['crt'] ?? '');
?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1><?= $perfil?'Editar perfil tributário':'Novo perfil tributário' ?></h1><p>Preencha com a orientação do responsável fiscal. Alterações geram uma versão nova.</p></div></header>
    <p><a href="<?= $e($url.'/perfis') ?>">Voltar aos perfis</a></p>
    <section class="bloco"><p>Regime (CRT): <strong><?= $e($crt ?: 'Não cadastrado') ?></strong> · UF: <strong><?= $e($empresa['estado'] ?? 'Não cadastrada') ?></strong> · NFC-e (65)</p>
        <form method="post" action="<?= $e($url.'/perfis/salvar') ?>" class="fiscal-form"><?php $token(); ?>
            <input type="hidden" name="id_perfil" value="<?= $e($perfil['id'] ?? '') ?>"><input type="hidden" name="versao" value="<?= $e($perfil['versao'] ?? '') ?>">
            <label for="fp-nome">Nome do perfil</label><input id="fp-nome" name="nome" required maxlength="120" value="<?= $v('nome') ?>">
            <label for="fp-op">Tipo de operação</label><select id="fp-op" name="operacao" required><option value="">Selecione</option><option value="producao" <?= ($dados['operacao'] ?? '')==='producao'?'selected':'' ?>>Produção própria</option><option value="revenda" <?= ($dados['operacao'] ?? '')==='revenda'?'selected':'' ?>>Revenda</option></select>
            <label for="fp-cfop">CFOP de saída interna</label><input id="fp-cfop" name="cfop" maxlength="4" inputmode="numeric" value="<?= $v('cfop') ?>">
            <label for="fp-icms"><?= in_array($crt,['1','4'],true)?'CSOSN':'CST do ICMS' ?></label><input id="fp-icms" name="situacao_icms" maxlength="3" inputmode="numeric" value="<?= $v('situacao_icms') ?>">
            <label for="fp-inicio">Vigência inicial</label><input id="fp-inicio" type="date" name="inicio" value="<?= $v('inicio') ?>">
            <details><summary>Tributação avançada (quando aplicável)</summary><div class="fiscal-form">
                <?php foreach (['cst_pis'=>'CST PIS','cst_cofins'=>'CST COFINS','cbenef'=>'Código de benefício fiscal','cst_ibs_cbs'=>'CST IBS/CBS','cclass_trib'=>'Classificação IBS/CBS (cClassTrib)','cst_is'=>'CST Imposto Seletivo','cclass_is'=>'Classificação Imposto Seletivo'] as $k=>$rot): ?>
                    <label for="fp-<?= $e($k) ?>"><?= $e($rot) ?></label><input id="fp-<?= $e($k) ?>" name="<?= $e($k) ?>" maxlength="10" value="<?= $v($k) ?>">
                <?php endforeach; ?>
                <?php foreach (Validar::ALIQUOTAS as $k): ?><label for="fp-aliq-<?= $e($k) ?>"><?= $e(strtoupper(str_replace('_',' ',$k))) ?> (%)</label><input id="fp-aliq-<?= $e($k) ?>" name="aliq_<?= $e($k) ?>" inputmode="decimal" maxlength="10" value="<?= $v('aliq_'.$k) ?>"><?php endforeach; ?>
                <p class="texto-suave">Deixe em branco quando não aplicável. A validação completa dos grupos XML será feita antes da emissão.</p>
            </div></details>
            <label for="fp-ref">Referência da orientação fiscal</label><input id="fp-ref" name="referencia" maxlength="250" value="<?= $v('referencia') ?>" placeholder="Ex.: orientação do contador em 25/11">
            <label class="fiscal-check"><input type="checkbox" name="revisado" value="1"> Confirmo que o responsável fiscal revisou esta versão para o regime, UF e operação indicados.</label>
            <?php if ($perfil): ?><p>Versão atual: <?= $e($perfil['versao']) ?>. <?= $e($perfil['vinculados']) ?> produtos vinculados. Os vínculos anteriores não serão atualizados automaticamente.</p><label class="fiscal-check"><input type="checkbox" name="confirmar_impacto" value="1" required> Confirmo a nova versão e a necessidade de revisar os vínculos.</label><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?> Salvar perfil</button>
        </form>
    </section>
</div>
