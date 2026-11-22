<?php
/**
 * Cadastro/edicao de um grupo de complementos com as opcoes.
 * Espera: $grupo (null = novo), $valores, $imagens (id da opcao => foto), $erros, $insumos (mapaParaFicha), $quantidadeLiberada, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Services\Gerencia\ComplementoService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\MidiaUrl;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $campo): string => is_string($valores[$campo] ?? null) ? $valores[$campo] : '';
$novo = $grupo === null;
$acao = $novo ? $base . '/estoque/complementos' : $base . '/estoque/complementos/' . (int) $grupo['id'];
$linhas = is_array($valores['opcoes'] ?? null) ? $valores['opcoes'] : [];
if ($linhas === []) {
    $linhas = [['disponivel' => '1']];
}
$erroGeral = $erros['_geral'] ?? null;
$errosCampos = array_filter($erros, static fn (string $k): bool => !str_starts_with($k, 'opcoes.') && $k !== '_geral', ARRAY_FILTER_USE_KEY);

/** Uma opcao do grupo (tambem e o modelo para "Adicionar opção", com o indice __i__). */
$qtdLiberada = !empty($quantidadeLiberada);
$linha = static function (string $i, array $l) use ($e, $insumos, $erros, $imagens, $qtdLiberada): string {
    $val = static fn (string $c): string => is_string($l[$c] ?? null) ? $l[$c] : '';
    $id = $val('id');
    $err = static fn (string $c): string => isset($erros['opcoes.' . $i . '.' . $c]) ? '<p class="field-msg ficha-erro" role="alert">' . $e($erros['opcoes.' . $i . '.' . $c]) . '</p>' : '';
    $foto = $id !== '' ? ($imagens[(int) $id] ?? null) : null;
    $opcoesInsumo = '<option value="">Não gasta insumo</option>';
    foreach ($insumos as $ins) {
        $opcoesInsumo .= '<option value="' . $ins['id'] . '"' . ((string) $ins['id'] === $val('insumo') ? ' selected' : '') . '>'
            . $e($ins['nome'] . ' (' . UnidadeMedida::from($ins['unidade'])->sigla() . ')' . ($ins['ativo'] ? '' : ' (desativado)')) . '</option>';
    }
    $n = 'opcoes[' . $i . ']';
    $maisAberto = $val('codigo') !== '' || $val('descricao') !== '' || $val('insumo') !== '' || isset($erros['opcoes.' . $i . '.codigo']) || isset($erros['opcoes.' . $i . '.insumo']);
    return '<div class="opcao-linha" data-opcao-linha>'
        . ($id !== '' ? '<input type="hidden" name="' . $n . '[id]" value="' . $e($id) . '">' : '')
        . '<div class="opcao-principal">'
        . '<label class="opcao-campo opcao-nome"><span>Nome da opção</span><input class="campo-simples" name="' . $n . '[nome]" value="' . $e($val('nome')) . '" maxlength="120" placeholder="Ex.: Bacon" autocomplete="off"></label>'
        . '<label class="opcao-campo opcao-preco"><span>Preço a mais</span><input class="campo-simples" name="' . $n . '[preco]" value="' . $e($val('preco')) . '" inputmode="numeric" data-mask="money" placeholder="0,00" autocomplete="off"></label>'
        . '<label class="opcao-disponivel"><input type="checkbox" name="' . $n . '[disponivel]" value="1"' . ($val('disponivel') === '1' ? ' checked' : '') . '><span>Disponível</span></label>'
        . '</div>'
        . ($qtdLiberada ? '<label class="opcao-campo opcao-qtd-max" data-so-repetir><span>Até quantas desta opção <span class="field-optional">(vazio = livre)</span></span>'
            . '<input class="campo-simples" name="' . $n . '[qtd_max]" value="' . $e($val('qtd_max')) . '" type="number" min="1" max="30" inputmode="numeric" placeholder="Livre"></label>' : '')
        . $err('nome') . $err('preco') . $err('qtd_max')
        . '<div class="opcao-foto">'
        . ($foto ? '<img src="' . $e(MidiaUrl::publica($foto)) . '" alt="Foto atual" width="56" height="56" loading="lazy">'
            . '<label class="opcao-tirar"><input type="checkbox" name="' . $n . '[tirar_foto]" value="1"><span>Tirar foto</span></label>' : '')
        . '<label class="opcao-campo"><span>' . ($foto ? 'Trocar foto' : 'Foto') . ' <span class="field-optional">(opcional)</span></span>'
        . '<input type="file" name="opcao_foto[' . $i . ']" accept="image/png,image/jpeg,image/webp"></label>'
        . '</div>' . $err('foto')
        . '<details class="opcao-mais"' . ($maisAberto ? ' open' : '') . '><summary>Mais detalhes: código, descrição e estoque</summary>'
        . '<div class="opcao-mais-grade">'
        . '<label class="opcao-campo"><span>Código <span class="field-optional">(opcional)</span></span><input class="campo-simples" name="' . $n . '[codigo]" value="' . $e($val('codigo')) . '" maxlength="40" autocomplete="off"></label>'
        . '<label class="opcao-campo"><span>Descrição <span class="field-optional">(opcional)</span></span><input class="campo-simples" name="' . $n . '[descricao]" value="' . $e($val('descricao')) . '" maxlength="255" autocomplete="off"></label>'
        . '<label class="opcao-campo"><span>Insumo que gasta</span><select name="' . $n . '[insumo]">' . $opcoesInsumo . '</select></label>'
        . '<label class="opcao-campo"><span>Quanto gasta em cada uma</span><input class="campo-simples" name="' . $n . '[quantidade]" value="' . $e($val('quantidade')) . '" inputmode="decimal" placeholder="Ex.: 0,030" data-mask="quantity" autocomplete="off"></label>'
        . '</div></details>' . $err('codigo') . $err('descricao') . $err('insumo')
        . ($id !== ''
            ? '<label class="opcao-remover"><input type="checkbox" name="' . $n . '[remover]" value="1"' . (($l['remover'] ?? '') === '1' ? ' checked' : '') . '><span>Remover esta opção</span></label>'
            : '<button class="link-acao link-perigo" type="button" data-opcao-remover>' . Icon::svg('x', 'icon-sm') . 'Remover</button>')
        . '</div>';
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/estoque/complementos') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Complementos</a>
            <h1><?= $novo ? 'Novo grupo de complementos' : $e('Editar: ' . $grupo['nome']) ?></h1>
            <p>Um grupo junta opções parecidas. Exemplo: "Adicionais" com Bacon, Cheddar e Ovo.</p>
        </div>
    </header>

    <?php $resumo = $errosCampos;
    if (array_filter(array_keys($erros), static fn (string $k): bool => str_starts_with($k, 'opcoes.')) !== []) {
        $resumo['opcoes'] ??= 'Confira as opções destacadas abaixo.';
    } ?>
    <?= Campo::resumoErros($resumo) ?>
    <?php if ($erroGeral !== null && $errosCampos === []): ?>
        <div class="notice notice-warning" role="alert"><?= Icon::svg('circle-x') ?><div><p><?= $e($erroGeral) ?></p></div></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($acao) ?>" enctype="multipart/form-data" novalidate data-validate-form data-grupo-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <section class="bloco" aria-labelledby="titulo-grupo">
            <div class="bloco-cabecalho"><h2 id="titulo-grupo"><?= Icon::svg('square-plus') ?>Grupo</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome do grupo', 'valor' => $v('nome'), 'icone' => 'tag', 'erro' => $erros['nome'] ?? null, 'classe' => 'col-inteira',
                    'dica' => 'Aparece para o cliente. Exemplo: Escolha a borda', 'attrs' => ['maxlength' => 80, 'data-validate' => 'required', 'autocomplete' => 'off']]) ?>
                <?= Campo::texto(['nome' => 'minimo', 'rotulo' => 'Mínimo de escolhas', 'valor' => $v('minimo'), 'icone' => 'minus', 'erro' => $erros['minimo'] ?? null, 'tipo' => 'number',
                    'dica' => '0 = o cliente pode pular. 1 ou mais = obrigatório.', 'attrs' => ['min' => 0, 'max' => ComplementoService::MAX_ESCOLHAS, 'inputmode' => 'numeric']]) ?>
                <?= Campo::texto(['nome' => 'maximo', 'rotulo' => 'Máximo de escolhas', 'valor' => $v('maximo'), 'icone' => 'plus', 'erro' => $erros['maximo'] ?? null, 'tipo' => 'number',
                    'dica' => 'Exemplo: borda = 1; adicionais = 5.', 'attrs' => ['min' => 1, 'max' => ComplementoService::MAX_ESCOLHAS, 'inputmode' => 'numeric']]) ?>
            </div>
            <?php if ($qtdLiberada): ?>
                <label class="alternador grupo-repetir">
                    <span><strong>O cliente escolhe a quantidade de cada opção</strong>
                        <small>Ex.: "Quantos ovos?" com − e +. O máximo de escolhas acima vale para a soma de tudo; em cada opção dá para limitar (vazio = livre). Desligado, cada opção é escolhida uma vez só.</small></span>
                    <input type="checkbox" name="repetir" value="1" data-grupo-repetir<?= $v('repetir') === '1' ? ' checked' : '' ?>><span class="alternador-chave" aria-hidden="true"></span>
                </label>
            <?php endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-opcoes" id="campo-opcoes">
            <div class="bloco-cabecalho">
                <h2 id="titulo-opcoes"><?= Icon::svg('list') ?>Opções</h2>
                <p>Preço a mais é somado ao preço do produto. Deixe 0,00 se for grátis. Fotos: PNG, JPG ou WebP, a partir de 300 x 275 pixels.</p>
            </div>
            <?php if (isset($erros['opcoes'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['opcoes']) ?></p><?php endif; ?>
            <div class="opcoes-lista" data-opcoes data-proximo="<?= max(array_map("intval", array_keys($linhas))) + 1 ?>">
                <?php foreach (array_values($linhas) as $i => $l): ?>
                    <?= $linha((string) array_keys($linhas)[$i], is_array($l) ? $l : []) ?>
                <?php endforeach; ?>
            </div>
            <template data-opcao-modelo><?= $linha('__i__', ['disponivel' => '1']) ?></template>
            <button class="btn btn-outline btn-sm" type="button" data-opcao-adicionar><?= Icon::svg('plus', 'icon-sm') ?>Adicionar opção</button>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="<?= $e($base . '/estoque/complementos') ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?><?= $novo ? 'Cadastrar grupo' : 'Salvar alterações' ?></button>
        </div>
    </form>

    <?php if (!$novo): ?>
        <section class="bloco bloco-perigo" aria-labelledby="titulo-excluir">
            <div class="bloco-cabecalho">
                <h2 id="titulo-excluir"><?= Icon::svg('trash') ?>Excluir grupo</h2>
                <p>O grupo sai de todos os produtos. Os pedidos antigos continuam mostrando o que o cliente escolheu.</p>
            </div>
            <form method="post" action="<?= $e($base . '/estoque/complementos/' . (int) $grupo['id'] . '/excluir') ?>"
                  data-confirm="O grupo e as opções dele saem do cardápio e de todos os produtos." data-confirm-titulo="Excluir &quot;<?= $e((string) $grupo['nome']) ?>&quot;?" data-confirm-acao="Excluir grupo" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-perigo btn-sm" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Excluir este grupo</button>
            </form>
        </section>
    <?php endif; ?>
</div>
