<?php
/**
 * Cadastro/edicao de produto com ficha tecnica.
 * Espera: $produto (null = novo), $valores, $erros, $categorias, $insumos (mapaParaFicha), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Services\Gerencia\ProdutoService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $campo): string => is_string($valores[$campo] ?? null) ? $valores[$campo] : '';
$novo = $produto === null;
$acao = $novo ? $base . '/estoque/produtos' : $base . '/estoque/produtos/' . (int) $produto['id'];
$opcoesCategoria = [];
foreach ($categorias as $c) {
    $opcoesCategoria[(string) $c['id']] = $c['nome'];
}
$opcoesCategoria[ProdutoService::CATEGORIA_NOVA] = '+ Criar nova categoria';
$controle = $v('controle') === 'livre' ? 'livre' : 'estoque';
$fichaInsumos = is_array($valores['ficha_insumo'] ?? null) ? $valores['ficha_insumo'] : [];
$fichaQtds = is_array($valores['ficha_quantidade'] ?? null) ? $valores['ficha_quantidade'] : [];
if ($fichaInsumos === []) {
    $fichaInsumos = [''];
    $fichaQtds = [''];
}

/** Uma linha da ficha (tambem usada como modelo para o botao "Adicionar insumo"). */
$linhaFicha = static function (string $idInsumo, string $qtd) use ($insumos, $e): string {
    $sigla = isset($insumos[(int) $idInsumo]) ? UnidadeMedida::from($insumos[(int) $idInsumo]['unidade'])->sigla() : '';
    $opcoes = '<option value="">Escolha o insumo</option>';
    foreach ($insumos as $i) {
        $u = UnidadeMedida::from($i['unidade']);
        $opcoes .= '<option value="' . $i['id'] . '" data-unidade="' . $e($u->sigla()) . '" data-custo="' . $e((string) ($i['custo'] ?? '')) . '"'
            . ((string) $i['id'] === $idInsumo ? ' selected' : '') . '>' . $e($i['nome'] . ($i['ativo'] ? '' : ' (desativado)')) . '</option>';
    }
    return '<div class="ficha-linha" data-ficha-linha>'
        . '<label><span class="sr-only">Insumo</span><select name="ficha_insumo[]" data-ficha-insumo>' . $opcoes . '</select></label>'
        . '<label class="ficha-qtd"><span class="sr-only">Quantidade por produto</span><input name="ficha_quantidade[]" value="' . $e($qtd) . '" inputmode="decimal" autocomplete="off" placeholder="Qtd." data-mask="quantity" data-ficha-qtd>'
        . '<span data-ficha-unidade>' . $e($sigla) . '</span></label>'
        . '<button class="btn-icone is-perigo" type="button" data-ficha-remover aria-label="Remover este insumo da ficha">' . Icon::svg('x', 'icon-sm') . '</button>'
        . '</div>';
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/estoque/produtos') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Produtos</a>
            <h1><?= $novo ? 'Novo produto' : $e('Editar: ' . $produto['nome']) ?></h1>
            <p>Os campos com <strong>(opcional)</strong> podem ficar em branco.</p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($acao) ?>" novalidate data-validate-form data-produto-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-dados">
            <div class="bloco-cabecalho"><h2 id="titulo-dados"><?= Icon::svg('hamburger') ?>O que você vende</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome do produto', 'valor' => $v('nome'), 'icone' => 'utensils-crossed', 'erro' => $erros['nome'] ?? null, 'classe' => 'col-inteira',
                    'dica' => 'Como aparece no cardápio. Exemplo: X-Burger', 'attrs' => ['maxlength' => 150, 'data-validate' => 'required', 'autocomplete' => 'off']]) ?>
                <?= Campo::texto(['nome' => 'preco', 'rotulo' => 'Preço de venda', 'valor' => $v('preco'), 'icone' => 'banknote', 'erro' => $erros['preco'] ?? null,
                    'dica' => 'Exemplo: 24,90', 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'data-validate' => 'required', 'autocomplete' => 'off', 'placeholder' => '0,00', 'data-preco']]) ?>
                <?= Campo::selecao(['nome' => 'categoria', 'rotulo' => 'Categoria', 'valor' => $v('categoria'), 'icone' => 'tags', 'erro' => $erros['categoria'] ?? null, 'opcional' => true,
                    'vazio' => 'Sem categoria', 'dica' => 'Agrupa os produtos no cardápio (Lanches, Bebidas...).', 'attrs' => ['data-categoria']], $opcoesCategoria) ?>
                <div class="col-inteira" data-nova-categoria<?= $v('categoria') === ProdutoService::CATEGORIA_NOVA ? '' : ' hidden' ?>>
                    <?= Campo::texto(['nome' => 'nova_categoria', 'rotulo' => 'Nome da nova categoria', 'valor' => $v('nova_categoria'), 'icone' => 'plus', 'erro' => $erros['nova_categoria'] ?? null,
                        'opcional' => true, 'dica' => 'Ela é criada junto com o produto.', 'attrs' => ['maxlength' => 80, 'autocomplete' => 'off']]) ?>
                </div>
                <?= Campo::areaTexto(['nome' => 'descricao', 'rotulo' => 'Descrição', 'valor' => $v('descricao'), 'erro' => $erros['descricao'] ?? null, 'opcional' => true, 'classe' => 'col-inteira',
                    'dica' => 'O que vem no produto. Aparece para o cliente.', 'attrs' => ['maxlength' => 500, 'rows' => 3]]) ?>
                <?= Campo::texto(['nome' => 'codigo', 'rotulo' => 'Código', 'valor' => $v('codigo'), 'icone' => 'qr-code', 'erro' => $erros['codigo'] ?? null, 'opcional' => true,
                    'dica' => 'Para achar rápido no caixa. Exemplo: 101', 'attrs' => ['maxlength' => 40, 'autocomplete' => 'off']]) ?>
            </div>
            <?= Campo::alternador('disponivel', 'Disponível para venda', 'Desligue para pausar o produto sem apagar nada.', $v('disponivel') === '1') ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-estoque">
            <div class="bloco-cabecalho">
                <h2 id="titulo-estoque"><?= Icon::svg('package') ?>Estoque deste produto</h2>
                <p>Escolha se cada venda deve baixar os insumos do estoque.</p>
            </div>
            <fieldset class="opcoes-modo">
                <legend class="sr-only">Como controlar o estoque</legend>
                <label class="opcao-modo">
                    <input type="radio" name="controle" value="estoque"<?= $controle === 'estoque' ? ' checked' : '' ?> data-controle>
                    <span><strong>Baixar dos insumos (recomendado)</strong><small>Cada venda tira do estoque os insumos da ficha abaixo. Se um insumo acabar, o produto sai de venda sozinho.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="controle" value="livre"<?= $controle === 'livre' ? ' checked' : '' ?> data-controle>
                    <span><strong>Não controlar estoque</strong><small>O produto fica sempre à venda, até você pausar.</small></span>
                </label>
            </fieldset>

            <div class="ficha-area" data-ficha-area<?= $controle === 'livre' ? ' hidden' : '' ?>>
                <h3 class="ficha-titulo" id="campo-ficha">Ficha técnica: o que vai em <strong>1 unidade</strong> do produto</h3>
                <?php if ($insumos === []): ?>
                    <div class="notice notice-info">
                        <?= Icon::svg('info') ?>
                        <div><strong>Você ainda não tem insumos.</strong><p>Cadastre primeiro os insumos (pão, carne, bebidas...) e depois volte aqui. <a href="<?= $e($base . '/estoque/insumos/novo') ?>">Cadastrar insumo</a></p></div>
                    </div>
                <?php else: ?>
                    <p class="ficha-ajuda">Exemplo: um hambúrguer leva <strong>1 un</strong> de pão e <strong>0,080 kg</strong> de carne (80 gramas). Comprado pronto? Cadastre como insumo por unidade e use 1 un.</p>
                    <?php if (isset($erros['ficha'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['ficha']) ?></p><?php endif; ?>
                    <div class="ficha" data-ficha>
                        <?php foreach ($fichaInsumos as $i => $idInsumo): ?>
                            <?= $linhaFicha((string) $idInsumo, (string) ($fichaQtds[$i] ?? '')) ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-ficha-modelo><?= $linhaFicha('', '') ?></template>
                    <button class="link-acao" type="button" data-ficha-adicionar><?= Icon::svg('plus', 'icon-sm') ?>Adicionar insumo</button>
                    <p class="ficha-resumo" data-ficha-resumo hidden aria-live="polite"></p>
                <?php endif; ?>
            </div>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="<?= $e($base . '/estoque/produtos') ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?><?= $novo ? 'Cadastrar produto' : 'Salvar alterações' ?></button>
        </div>
    </form>

    <?php if (!$novo): ?>
        <section class="bloco bloco-perigo" aria-labelledby="titulo-excluir">
            <div class="bloco-cabecalho">
                <h2 id="titulo-excluir"><?= Icon::svg('trash') ?>Excluir produto</h2>
                <p>Prefere só tirar do cardápio por um tempo? Desligue "Disponível para venda".</p>
            </div>
            <form method="post" action="<?= $e($base . '/estoque/produtos/' . (int) $produto['id'] . '/excluir') ?>"
                  data-confirm="O produto e a ficha técnica dele serão apagados. Isso não pode ser desfeito."
                  data-confirm-titulo="Excluir &quot;<?= $e((string) $produto['nome']) ?>&quot;?" data-confirm-acao="Excluir produto" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-perigo btn-sm" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Excluir este produto</button>
            </form>
        </section>
    <?php endif; ?>
</div>
