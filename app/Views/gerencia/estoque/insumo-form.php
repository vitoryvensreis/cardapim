<?php
/**
 * Cadastro/edicao de insumo. Espera: $insumo (null = novo), $valores, $erros, $usos (nomes de produtos), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $campo): string => is_string($valores[$campo] ?? null) ? $valores[$campo] : '';
$novo = $insumo === null;
$acao = $novo ? $base . '/estoque/insumos' : $base . '/estoque/insumos/' . (int) $insumo['id'];
$unidadeAtual = UnidadeMedida::tryFrom($v('unidade'));
$sigla = $unidadeAtual?->sigla() ?? '';
$travada = !$novo && $usos !== [];
$exemplos = [
    'un' => ['Por unidade', 'Pão, lata de refrigerante, ovo, espetinho pronto', 'package'],
    'kg' => ['Por quilo (kg)', 'Carne, queijo, batata, farinha', 'scale'],
    'l' => ['Por litro (L)', 'Óleo, leite, chope, molho', 'coffee'],
];
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/estoque/insumos') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Insumos</a>
            <h1><?= $novo ? 'Novo insumo' : $e('Editar: ' . $insumo['nome']) ?></h1>
            <p>Insumo é tudo que você compra para preparar ou revender: ingredientes, bebidas, embalagens.</p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($acao) ?>" novalidate data-validate-form data-insumo-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-insumo">
            <div class="bloco-cabecalho"><h2 id="titulo-insumo"><?= Icon::svg('wheat') ?>Dados do insumo</h2></div>
            <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome', 'valor' => $v('nome'), 'icone' => 'wheat', 'erro' => $erros['nome'] ?? null,
                'dica' => 'Exemplo: Carne de hambúrguer', 'attrs' => ['maxlength' => 120, 'data-validate' => 'required', 'autocomplete' => 'off']]) ?>

            <fieldset class="opcoes-modo opcoes-unidade" id="campo-unidade">
                <legend class="rotulo-grupo">Como você conta este insumo?</legend>
                <?php foreach ($exemplos as $valor => [$titulo, $exemplo, $icone]):
                    $marcado = $v('unidade') === $valor;
                ?>
                    <label class="opcao-modo">
                        <input type="radio" name="unidade" value="<?= $valor ?>"<?= $marcado ? ' checked' : '' ?><?= $travada && !$marcado ? ' disabled' : '' ?>
                               data-unidade="<?= $e(UnidadeMedida::from($valor)->sigla()) ?>">
                        <span><strong><?= $e($titulo) ?></strong><small><?= $e($exemplo) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if (isset($erros['unidade'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['unidade']) ?></p><?php endif; ?>
                <?php if ($travada): ?>
                    <p class="field-msg"><?= Icon::svg('lock', 'icon-sm') ?> A unidade não pode mudar porque o insumo está na ficha de: <?= $e(implode(', ', $usos)) ?>.</p>
                <?php endif; ?>
            </fieldset>
        </section>

        <section class="bloco" aria-labelledby="titulo-quantidade">
            <div class="bloco-cabecalho">
                <h2 id="titulo-quantidade"><?= Icon::svg('boxes') ?>Quantidade</h2>
                <p>Pode usar casas decimais em quilo e litro. Exemplo: 2,5 kg.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'estoque_atual', 'rotulo' => 'Quanto tem agora', 'valor' => $v('estoque_atual'), 'icone' => 'package', 'erro' => $erros['estoque_atual'] ?? null,
                    'sufixo' => $sigla, 'dica' => 'O que está na prateleira hoje.', 'opcional' => true, 'attrs' => ['inputmode' => 'decimal', 'data-mask' => 'quantity', 'autocomplete' => 'off', 'placeholder' => '0']]) ?>
                <?= Campo::texto(['nome' => 'estoque_minimo', 'rotulo' => 'Estoque mínimo', 'valor' => $v('estoque_minimo'), 'icone' => 'bell', 'erro' => $erros['estoque_minimo'] ?? null,
                    'sufixo' => $sigla, 'dica' => 'Quando chegar nesse número, avisamos que é hora de comprar.', 'opcional' => true, 'attrs' => ['inputmode' => 'decimal', 'data-mask' => 'quantity', 'autocomplete' => 'off', 'placeholder' => '0']]) ?>
                <?= Campo::texto(['nome' => 'custo_unitario', 'rotulo' => 'Quanto você paga', 'valor' => $v('custo_unitario'), 'icone' => 'banknote', 'erro' => $erros['custo_unitario'] ?? null,
                    'sufixo' => $sigla !== '' ? 'por ' . $sigla : '', 'opcional' => true, 'dica' => 'Preço de compra de 1 unidade, 1 kg ou 1 litro. Ajuda a calcular o custo dos produtos.',
                    'attrs' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => 'R$ 0,00', 'data-custo']]) ?>
            </div>
            <?= Campo::alternador('ativo', 'Insumo ativo', 'Desativado, ele some das listas e os produtos que usam ele saem de venda.', $v('ativo') === '1') ?>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="<?= $e($base . '/estoque/insumos') ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?><?= $novo ? 'Cadastrar insumo' : 'Salvar alterações' ?></button>
        </div>
    </form>

    <?php if (!$novo): ?>
        <section class="bloco bloco-perigo" aria-labelledby="titulo-excluir">
            <div class="bloco-cabecalho">
                <h2 id="titulo-excluir"><?= Icon::svg('trash') ?>Excluir insumo</h2>
                <p><?= $usos === [] ? 'O insumo será apagado. Isso não pode ser desfeito.' : 'Este insumo está em fichas técnicas. Tire ele das fichas antes de excluir, ou apenas desative.' ?></p>
            </div>
            <form method="post" action="<?= $e($base . '/estoque/insumos/' . (int) $insumo['id'] . '/excluir') ?>"
                  data-confirm="O insumo será apagado. Isso não pode ser desfeito."
                  data-confirm-titulo="Excluir &quot;<?= $e((string) $insumo['nome']) ?>&quot;?" data-confirm-acao="Excluir insumo" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-perigo btn-sm" type="submit"<?= $usos !== [] ? ' disabled' : '' ?>><?= Icon::svg('trash', 'icon-sm') ?>Excluir este insumo</button>
            </form>
        </section>
    <?php endif; ?>
</div>
