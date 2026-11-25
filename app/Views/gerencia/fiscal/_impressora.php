<?php
use Cardapim\Core\Icon;
/** Card independente; não faz parte do formulário que regrava os pontos atuais. */
if (!($fiscalImpressora['habilitado'] ?? false)) { return; }
?>
<section class="bloco" id="cupom-fiscal" aria-labelledby="titulo-cupom-fiscal">
    <div class="bloco-cabecalho">
        <h2 id="titulo-cupom-fiscal"><?= Icon::svg('receipt') ?>Cupom fiscal</h2>
        <p>Escolha uma impressora cadastrada. Por padrão, usa a impressora padrão do Cupom completo.</p>
    </div>
    <p><strong><?= $e($fiscalImpressora['destino'] ?? 'Aguardando impressora fiscal') ?></strong></p>
    <form method="post" action="<?= $e($base . '/integracoes/impressoras/fiscal') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="impressao-teste-campo"><span>Impressora fiscal</span>
            <select name="impressora_fiscal">
                <option value="padrao" <?= $fiscalImpressora['escolha'] === null ? 'selected' : '' ?>>Usar padrão do Cupom completo</option>
                <?php if ($fiscalImpressora['escolha'] !== null && $fiscalImpressora['destino'] === null): ?>
                    <option value="" selected disabled>Impressora anterior indisponível — selecione outra</option>
                <?php endif; ?>
                <?php foreach ($impressoras as $imp): $fmt = $formatos[$imp['id']] ?? null; ?>
                    <option value="<?= (int) $imp['id'] ?>" <?= $fiscalImpressora['escolha'] === $imp['id'] ? 'selected' : '' ?>><?= $e($imp['apelido']) ?><?= $fmt !== null && $fmt['informado'] ? ' — ' . (int) $fmt['papel'] . ' mm' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="bloco-rodape"><button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?>Salvar impressora fiscal</button></div>
    </form>
    <p class="texto-suave">Papel, logo e margem são os da impressora existente. Não modifica o Cupom completo nem o Agent.</p>
</section>
