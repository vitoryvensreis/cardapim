<?php
/** Novo cupom / editar cupom. Espera: $cupom (null = novo), $valores, $erros, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $k): string => (string) ($valores[$k] ?? '');
$novo = $cupom === null;
$acao = $novo ? $base . '/cupons' : $base . '/cupons/' . (int) $cupom['id'];
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/cupons') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Cupons</a>
            <h1><?= $novo ? 'Novo cupom' : 'Cupom ' . $e((string) $cupom['codigo']) ?></h1>
            <p><?= $novo ? 'Escolha o código, o desconto e quantas vezes ele pode ser usado.' : 'Usado ' . (int) $cupom['usos'] . ((int) $cupom['usos'] === 1 ? ' vez' : ' vezes') . ' até agora. Pedidos cancelados não contam.' ?></p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($acao) ?>" novalidate data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-codigo">
            <div class="bloco-cabecalho"><h2 id="titulo-codigo"><?= Icon::svg('ticket-percent') ?>Código e desconto</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'codigo', 'rotulo' => 'Código do cupom', 'valor' => $v('codigo'), 'icone' => 'ticket-percent', 'erro' => $erros['codigo'] ?? null,
                    'dica' => 'O que o cliente digita. Letras e números, sem espaços (ex.: BEMVINDO10).', 'classe' => 'cl-campo-codigo',
                    'attrs' => ['maxlength' => 20, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
            </div>
            <fieldset class="opcoes-modo cl-opcoes cl-opcoes-2" id="campo-tipo">
                <legend class="rotulo-grupo">Tipo de desconto</legend>
                <label class="opcao-modo">
                    <input type="radio" name="tipo" value="percentual"<?= $v('tipo') !== 'fixo' ? ' checked' : '' ?>>
                    <span><strong><?= Icon::svg('percent', 'icon-sm') ?>Porcentagem</strong><small>Ex.: 10% do valor dos itens.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="tipo" value="fixo"<?= $v('tipo') === 'fixo' ? ' checked' : '' ?>>
                    <span><strong><?= Icon::svg('banknote', 'icon-sm') ?>Valor fixo</strong><small>Ex.: R$ 5,00 de desconto.</small></span>
                </label>
            </fieldset>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'valor', 'rotulo' => 'Valor do desconto', 'valor' => $v('valor'), 'icone' => 'tag', 'erro' => $erros['valor'] ?? null,
                    'dica' => 'Porcentagem: digite 10 para 10%. Valor fixo: digite 5,00.', 'attrs' => ['inputmode' => 'decimal', 'maxlength' => 12]]) ?>
                <?= Campo::texto(['nome' => 'pedido_minimo', 'rotulo' => 'Pedido mínimo', 'valor' => $v('pedido_minimo'), 'icone' => 'banknote', 'opcional' => true,
                    'erro' => $erros['pedido_minimo'] ?? null, 'dica' => 'Valor dos itens para o cupom valer. Vazio = qualquer valor.',
                    'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00']]) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-uso">
            <div class="bloco-cabecalho"><h2 id="titulo-uso"><?= Icon::svg('repeat') ?>Quantas vezes pode ser usado</h2></div>
            <fieldset class="opcoes-modo cl-opcoes" id="campo-uso">
                <legend class="sr-only">Limite de usos</legend>
                <label class="opcao-modo">
                    <input type="radio" name="uso" value="varios"<?= $v('uso') === 'varios' || $v('uso') === '' ? ' checked' : '' ?>>
                    <span><strong>Várias vezes, sem limite</strong><small>Qualquer cliente pode usar enquanto o cupom estiver ativo.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="uso" value="limite"<?= $v('uso') === 'limite' ? ' checked' : '' ?>>
                    <span><strong>Até um número de vezes</strong><small>Ex.: os 50 primeiros pedidos. Depois disso o cupom esgota.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="uso" value="unico"<?= $v('uso') === 'unico' ? ' checked' : '' ?>>
                    <span><strong>Só 1 vez no total</strong><small>Um cupom de presente: o primeiro que usar, levou.</small></span>
                </label>
            </fieldset>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'limite_usos', 'rotulo' => 'Quantas vezes no total', 'valor' => $v('limite_usos'), 'icone' => 'hash', 'opcional' => true,
                    'erro' => $erros['limite_usos'] ?? null, 'dica' => 'Só se escolher "até um número de vezes".', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 6]]) ?>
            </div>
            <?= Campo::alternador('um_por_cliente', 'Cada cliente usa só uma vez', 'O mesmo cliente não consegue usar o cupom em dois pedidos.', $v('um_por_cliente') === '1') ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-validade">
            <div class="bloco-cabecalho"><h2 id="titulo-validade"><?= Icon::svg('calendar') ?>Validade e situação</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'valido_ate', 'rotulo' => 'Vale até', 'valor' => $v('valido_ate'), 'icone' => 'calendar', 'tipo' => 'date', 'opcional' => true,
                    'erro' => $erros['valido_ate'] ?? null, 'dica' => 'Vazio = não vence. Vale até o fim do dia escolhido.']) ?>
            </div>
            <?php if (!$novo): ?>
                <?= Campo::alternador('ativo', 'Cupom ativo', 'Desligado, o cupom fica pausado e ninguém consegue usar.', $v('ativo') === '1') ?>
            <?php endif; ?>
        </section>

        <div class="form-acoes">
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?><?= $novo ? 'Criar cupom' : 'Salvar cupom' ?></button>
            <a class="btn btn-outline" href="<?= $e($base . '/cupons') ?>">Cancelar</a>
        </div>
    </form>
</div>
