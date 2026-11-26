<?php require __DIR__.'/_base.php'; ?>
<div class="painel-page fiscal-restaurante fiscal-produtos">
    <header class="painel-cabecalho"><div><h1>Composição fiscal: <?= $e($produto['nome']) ?></h1><p>Defina como classificar os componentes sem alterar o combo, os complementos ou os valores comerciais.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos/validacao') ?>">Voltar à conferência fiscal</a></header>
    <section class="bloco"><div class="bloco-cabecalho"><h2><?= $estrutura['combo']?'Combo — componentes separados':'Produto e complementos' ?></h2></div>
        <p><?= $estrutura['combo']?'O sistema comercial já reparte o combo em linhas. A proposta fiscal considera os componentes, sem duplicar o produto pai.':'Cada complemento deve ser classificado como parte do principal ou usar uma referência fiscal própria, conforme a orientação contábil.' ?></p>
        <?php foreach ($estrutura['fixos'] as $f): ?><div class="fiscal-linha"><strong><?= $e($produtos[$f['id_produto']]['nome'] ?? ('Produto '.$f['id_produto'].' indisponível')) ?></strong><small>Quantidade <?= $e($f['quantidade']) ?> · classificação do componente</small></div><?php endforeach; ?>
        <form method="post" action="<?= $e($url.'/produtos/'.$produto['id'].'/composicao') ?>" class="fiscal-form"><?php $token(); ?>
            <input type="hidden" name="assinatura" value="<?= $e($assinatura) ?>">
            <?php foreach ($estrutura['opcoes'] as $oid=>$o): $regra=$config['dados']['opcoes'][$oid] ?? []; ?>
                <div class="fiscal-linha"><div><strong><?= $e($o['nome']) ?></strong><small><?= $e($o['grupo']) ?> · R$ <?= $e(number_format((float)$o['preco'],2,',','.')) ?></small></div>
                    <?php if ($estrutura['combo'] && $o['ligado']!==null): ?><p>Usará a classificação do produto vinculado: <?= $e($produtos[$o['ligado']]['nome'] ?? 'Produto indisponível') ?></p>
                    <?php else: ?>
                        <div class="fiscal-form"><label for="modo-<?= $oid ?>">Tratamento fiscal</label><select id="modo-<?= $oid ?>" name="opcoes[<?= $oid ?>][modo]" required><option value="">Selecione</option><?php if (!$estrutura['combo']): ?><option value="principal" <?= ($regra['modo'] ?? '')==='principal'?'selected':'' ?>>Compor o produto principal</option><?php endif; ?><option value="produto" <?= ($regra['modo'] ?? '')==='produto'?'selected':'' ?>>Classificação separada por referência</option></select>
                            <label for="ref-<?= $oid ?>">Produto fiscal de referência (se separado)</label><select id="ref-<?= $oid ?>" name="opcoes[<?= $oid ?>][produto]"><option value="">Selecione</option><?php foreach ($produtos as $p): if ((int)$p['id']===(int)$produto['id'] || !empty($estruturas[(int)$p['id']]['combo'])) { continue; } ?><option value="<?= $e($p['id']) ?>" <?= (int)($regra['produto'] ?? 0)===(int)$p['id']?'selected':'' ?>><?= $e($p['nome']) ?></option><?php endforeach; ?></select>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <label for="comp-referencia">Referência da orientação fiscal</label><input id="comp-referencia" name="referencia" maxlength="250" required value="<?= $e($config['dados']['referencia'] ?? '') ?>">
            <?php $semelhantes=\Cardapim\Services\Gerencia\FiscalProdutosService::semelhantes((int)$produto['id'],$estruturas); if ($semelhantes>0): ?>
                <label class="fiscal-check"><input type="checkbox" name="aplicar_semelhantes" value="1" checked> Aplicar o mesmo tratamento a mais <?= $e($semelhantes) ?> produtos que usam estes complementos</label>
            <?php endif; ?>
            <label class="fiscal-check"><input type="checkbox" name="confirmado" value="1" required> Confirmei a composição e as referências fiscais com o responsável fiscal.</label>
            <button class="btn btn-primary" type="submit">Salvar composição fiscal</button>
        </form>
        <p class="texto-suave">Isso não cria produto, não mexe no estoque nem no preço. Mudar só o preço de um complemento não exige nova revisão.</p>
    </section>
</div>
