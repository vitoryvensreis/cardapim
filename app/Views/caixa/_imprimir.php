<?php
/**
 * Botao Imprimir pelo Deliveryzim Agent (imprimir-agent.js).
 * Ponto com nenhuma ou uma impressora: imprime direto. Com duas ou mais: abre a escolha pelos apelidos,
 * com a padrao em destaque (um toque a mais so quando existe escolha).
 * Espera: $acaoImprimir (url), $rotuloImprimir, $impressoras (FilaImpressao::opcoes), $csrf, $e.
 */
use Cardapim\Core\Icon;

$impressoras ??= [];
?>
<?php if (count($impressoras) <= 1): ?>
    <form method="post" action="<?= $e((string) $acaoImprimir) ?>" class="form-imprimir" data-imprimir-agent>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?php if (count($impressoras) === 1): ?><input type="hidden" name="impressora" value="<?= (int) $impressoras[0]['id'] ?>"><?php endif; ?>
        <button class="btn btn-outline btn-sm" type="submit"<?= count($impressoras) === 1 ? ' title="Imprime na ' . $e($impressoras[0]['apelido']) . '"' : '' ?>><?= Icon::svg('printer', 'icon-sm') ?><?= $e((string) $rotuloImprimir) ?></button>
    </form>
<?php else: ?>
    <details class="imprimir-escolha" data-imprimir-escolha>
        <summary class="btn btn-outline btn-sm"><?= Icon::svg('printer', 'icon-sm') ?><?= $e((string) $rotuloImprimir) ?><?= Icon::svg('chevron-down', 'icon-sm') ?></summary>
        <form method="post" action="<?= $e((string) $acaoImprimir) ?>" class="imprimir-escolha-lista" data-imprimir-agent>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <p>Imprimir em qual impressora?</p>
            <?php foreach ($impressoras as $imp): ?>
                <button class="btn btn-block <?= $imp['padrao'] ? 'btn-primary' : 'btn-outline' ?>" type="submit" name="impressora" value="<?= (int) $imp['id'] ?>">
                    <?= Icon::svg('printer', 'icon-sm') ?><span><?= $e($imp['apelido']) ?><?php if (!$imp['ligada']): ?> <small>(desligada)</small><?php endif; ?></span>
                </button>
            <?php endforeach; ?>
        </form>
    </details>
<?php endif; ?>
