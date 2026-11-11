<?php
/**
 * Fechar caixa com contagem "cega": a pessoa conta o dinheiro antes de ver quanto o sistema esperava.
 * Espera: $caixa, $comandasAbertas, $base, $csrf, $erros, $enviado.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1>Fechar caixa</h1>
            <p>Conte as notas e moedas da gaveta. Depois de fechar, você vê se sobrou ou faltou.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= $e($base) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Voltar</a>
    </header>

    <?php if ($comandasAbertas > 0): ?>
        <div class="notice notice-info bloco-aviso" role="note">
            <?= Icon::svg('info') ?>
            <div>
                <strong><?= (int) $comandasAbertas ?> <?= $comandasAbertas === 1 ? 'conta continua aberta' : 'contas continuam abertas' ?></strong>
                <p>Tudo bem fechar o caixa assim: elas ficam abertas e são recebidas no próximo caixa. <a href="<?= $e($base . '/mesas') ?>">Ver mesas</a></p>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($base . '/fechar') ?>" class="bloco caixa-fechar" novalidate data-validate-form
          data-confirm="Depois de fechar, as vendas novas só entram no próximo caixa." data-confirm-titulo="Fechar o caixa agora?" data-confirm-acao="Fechar caixa">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <ol class="caixa-passos">
            <li><?= Icon::svg('banknote', 'icon-sm') ?>Tire da gaveta todas as notas e moedas.</li>
            <li><?= Icon::svg('calculator', 'icon-sm') ?>Some tudo, sem contar os comprovantes de cartão.</li>
            <li><?= Icon::svg('pencil', 'icon-sm') ?>Digite o total abaixo.</li>
        </ol>
        <?= Campo::texto(['nome' => 'dinheiro_contado', 'id' => 'dinheiro-contado', 'rotulo' => 'Dinheiro contado na gaveta', 'valor' => $enviado['dinheiro_contado'] ?? '', 'icone' => 'banknote',
            'erro' => $erros['dinheiro_contado'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00', 'autofocus' => true]]) ?>
        <?= Campo::areaTexto(['nome' => 'observacao', 'id' => 'fechar-obs', 'rotulo' => 'Observação', 'valor' => $enviado['observacao'] ?? '', 'opcional' => true,
            'erro' => $erros['observacao'] ?? null, 'attrs' => ['rows' => 2, 'maxlength' => 300, 'placeholder' => 'Ex.: deixei R$ 100 no cofre para o troco de amanhã']]) ?>
        <button class="btn btn-wine btn-lg btn-block" type="submit"><?= Icon::svg('lock', 'icon-sm') ?>Fechar caixa</button>
    </form>
</div>
