<?php
/**
 * Cadastro/edicao de conta a pagar ou a receber.
 * Espera: $conta (null = nova), $valores, $erros, $categorias, $formas, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\ContaFinanceiraService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$val = static fn (string $campo): string => is_string($valores[$campo] ?? null) ? $valores[$campo] : '';
$nova = $conta === null;
$tipoAtual = $val('tipo') === 'receber' ? 'receber' : 'pagar';
$acaoForm = $nova ? $base . '/financeiro/contas' : $base . '/financeiro/contas/' . (int) $conta['id'];
$paga = !$nova && $conta['status'] === 'paga';
$opcoesTipo = [
    'pagar' => ['Conta a pagar', 'Aluguel, luz, fornecedor, salário'],
    'receber' => ['Conta a receber', 'Encomenda, evento, venda fiado'],
];
$repetirOpcoes = ['1' => 'Não repetir'];
for ($m = 2; $m <= ContaFinanceiraService::MAX_REPETIR; $m++) {
    $repetirOpcoes[(string) $m] = 'Repetir por ' . $m . ' meses';
}
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/financeiro/contas') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Contas a pagar e receber</a>
            <h1><?= $nova ? 'Nova conta' : $e('Editar: ' . $conta['descricao']) ?></h1>
            <p>Anote a conta com o valor e o vencimento. Quando pagar ou receber, toque em <strong>Paguei</strong> ou <strong>Recebi</strong> na lista.</p>
        </div>
    </header>

    <?php if ($paga): ?>
        <div class="notice notice-info bloco-aviso" role="note">
            <?= Icon::svg('circle-check') ?>
            <div>
                <strong><?= $conta['tipo'] === 'receber' ? 'Recebida' : 'Paga' ?> em <?= $e(date('d/m/Y', (int) strtotime((string) $conta['pago_em']))) ?><?= $conta['forma'] ? ' (' . $e(ContaFinanceiraService::FORMAS[$conta['forma']] ?? '') . ')' : '' ?>.</strong>
                <p>Marcou por engano? Use <strong>Voltar para em aberto</strong> no fim da página.</p>
            </div>
        </div>
    <?php endif; ?>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($acaoForm) ?>" novalidate data-validate-form data-conta-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-conta">
            <div class="bloco-cabecalho"><h2 id="titulo-conta"><?= Icon::svg('receipt') ?>Dados da conta</h2></div>

            <fieldset class="opcoes-modo" id="campo-tipo">
                <legend class="rotulo-grupo">É uma conta a pagar ou a receber?</legend>
                <?php foreach ($opcoesTipo as $tipoValor => [$tipoTitulo, $tipoExemplo]): ?>
                    <label class="opcao-modo">
                        <input type="radio" name="tipo" value="<?= $tipoValor ?>"<?= $tipoAtual === $tipoValor ? ' checked' : '' ?> data-tipo-conta>
                        <span><strong><?= $e($tipoTitulo) ?></strong><small><?= $e($tipoExemplo) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if (isset($erros['tipo'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['tipo']) ?></p><?php endif; ?>
            </fieldset>

            <?= Campo::texto(['nome' => 'descricao', 'rotulo' => 'Descrição', 'valor' => $val('descricao'), 'icone' => 'pencil', 'erro' => $erros['descricao'] ?? null,
                'dica' => 'Exemplo: Aluguel do ponto, Distribuidora de bebidas, Encomenda de salgados', 'attrs' => ['maxlength' => 120, 'data-validate' => 'required', 'autocomplete' => 'off']]) ?>

            <div class="form-grade">
                <?php foreach ($categorias as $tipoCategoria => $listaCategorias): ?>
                    <div class="fin-categorias" data-categorias="<?= $tipoCategoria ?>"<?= $tipoCategoria !== $tipoAtual ? ' hidden' : '' ?>>
                        <?= Campo::selecao([
                            'nome' => 'categoria', 'id' => $tipoCategoria === $tipoAtual ? 'campo-categoria' : 'campo-categoria-' . $tipoCategoria, 'rotulo' => 'Categoria', 'icone' => 'tags',
                            'valor' => $tipoCategoria === $tipoAtual ? $val('categoria') : '', 'vazio' => 'Escolha a categoria',
                            'erro' => $tipoCategoria === $tipoAtual ? ($erros['categoria'] ?? null) : null,
                            'attrs' => $tipoCategoria !== $tipoAtual ? ['disabled' => true] : [],
                        ], $listaCategorias) ?>
                    </div>
                <?php endforeach; ?>
                <?= Campo::texto(['nome' => 'valor', 'rotulo' => 'Valor', 'valor' => $val('valor'), 'icone' => 'banknote', 'erro' => $erros['valor'] ?? null,
                    'attrs' => ['inputmode' => 'decimal', 'data-mask' => 'money', 'autocomplete' => 'off', 'placeholder' => 'R$ 0,00', 'data-validate' => 'required']]) ?>
                <?= Campo::texto(['nome' => 'vencimento', 'rotulo' => 'Vencimento', 'tipo' => 'date', 'valor' => $val('vencimento'), 'icone' => 'calendar', 'erro' => $erros['vencimento'] ?? null,
                    'attrs' => ['data-validate' => 'required']]) ?>
                <?php if ($nova): ?>
                    <?= Campo::selecao(['nome' => 'repetir', 'rotulo' => 'Repete todo mês?', 'icone' => 'calendar-range', 'valor' => $val('repetir') ?: '1', 'erro' => $erros['repetir'] ?? null,
                        'dica' => 'Para aluguel, internet e outras contas fixas. Cria uma conta por mês, no mesmo dia.'], $repetirOpcoes) ?>
                <?php endif; ?>
            </div>

            <?= Campo::areaTexto(['nome' => 'observacao', 'rotulo' => 'Observação', 'valor' => $val('observacao'), 'icone' => 'sticky-note', 'opcional' => true, 'erro' => $erros['observacao'] ?? null,
                'dica' => 'Número do boleto, nome de quem vai pagar, combinado com o cliente...', 'attrs' => ['maxlength' => 300, 'rows' => 3]]) ?>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="<?= $e($base . '/financeiro/contas') ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?><?= $nova ? 'Cadastrar conta' : 'Salvar alterações' ?></button>
        </div>
    </form>

    <?php if (!$nova): ?>
        <section class="bloco" aria-labelledby="titulo-baixa">
            <div class="bloco-cabecalho">
                <h2 id="titulo-baixa"><?= Icon::svg($paga ? 'undo-2' : 'circle-check') ?><?= $paga ? 'Voltar para em aberto' : ($conta['tipo'] === 'receber' ? 'Marcar como recebida' : 'Marcar como paga') ?></h2>
                <p><?= $paga ? 'Se marcou por engano, a conta volta para a lista de contas em aberto.' : 'Escolha o dia em que pagou ou recebeu. Ela passa a contar no resultado desse dia.' ?></p>
            </div>
            <?php if ($paga): ?>
                <form method="post" action="<?= $e($base . '/financeiro/contas/' . (int) $conta['id'] . '/reabrir') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline" type="submit"><?= Icon::svg('undo-2', 'icon-sm') ?>Voltar para em aberto</button>
                </form>
            <?php else: ?>
                <form class="fin-baixa" method="post" action="<?= $e($base . '/financeiro/contas/' . (int) $conta['id'] . '/baixar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <div class="form-grade">
                        <?= Campo::texto(['nome' => 'pago_em', 'rotulo' => $conta['tipo'] === 'receber' ? 'Dia em que recebeu' : 'Dia em que pagou', 'tipo' => 'date', 'valor' => date('Y-m-d'), 'icone' => 'calendar',
                            'attrs' => ['max' => date('Y-m-d')]]) ?>
                        <?= Campo::selecao(['nome' => 'forma', 'rotulo' => 'Como foi pago', 'icone' => 'wallet', 'valor' => '', 'vazio' => 'Não informar', 'opcional' => true], $formas) ?>
                    </div>
                    <button class="btn btn-wine" type="submit"><?= Icon::svg('check', 'icon-sm') ?><?= $conta['tipo'] === 'receber' ? 'Marcar como recebida' : 'Marcar como paga' ?></button>
                </form>
            <?php endif; ?>
        </section>

        <section class="bloco bloco-perigo" aria-labelledby="titulo-excluir">
            <div class="bloco-cabecalho">
                <h2 id="titulo-excluir"><?= Icon::svg('trash') ?>Excluir conta</h2>
                <p>Use só para contas cadastradas por engano. Valor desta conta: <?= $e(Numero::reais((string) $conta['valor'])) ?>.</p>
            </div>
            <form method="post" action="<?= $e($base . '/financeiro/contas/' . (int) $conta['id'] . '/excluir') ?>"
                  data-confirm="A conta será apagada. Isso não pode ser desfeito." data-confirm-titulo="Excluir &quot;<?= $e((string) $conta['descricao']) ?>&quot;?"
                  data-confirm-acao="Excluir conta" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline area-remover" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Excluir conta</button>
            </form>
        </section>
    <?php endif; ?>
</div>
