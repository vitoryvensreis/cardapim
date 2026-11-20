<?php
/**
 * Cadastro/edicao de produto com ficha tecnica.
 * Espera: $produto (null = novo), $valores, $erros, $categorias, $insumos (mapaParaFicha), $base, $csrf.
 * Opcional: $gruposComplemento (null = complementos ainda nao liberados), $fotoAtual,
 * $impressorasProducao (impressoras do Agent pelos apelidos; null = recurso ainda nao liberado).
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
$controle = ProdutoService::controle($v('controle'));
$siglaVenda = ['un' => 'un', 'kg' => 'kg', 'l' => 'L'];
$unidadeVenda = in_array($v('unidade_venda'), ['kg', 'l'], true) ? $v('unidade_venda') : 'un';
$porPeso = $unidadeVenda !== 'un';
$porcoesValores = array_values(array_filter(is_array($valores['porcoes'] ?? null) ? $valores['porcoes'] : [], 'is_string'));
$porcoesValores = array_pad(array_slice($porcoesValores, 0, ProdutoService::MAX_PORCOES), ProdutoService::MAX_PORCOES, '');
$nomeUnidade = ['un' => '1 unidade', 'kg' => '1 kg vendido', 'l' => '1 litro vendido'][$unidadeVenda];
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

    <form method="post" action="<?= $e($acao) ?>" enctype="multipart/form-data" novalidate data-validate-form data-produto-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-dados">
            <div class="bloco-cabecalho"><h2 id="titulo-dados"><?= Icon::svg('hamburger') ?>O que você vende</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome do produto', 'valor' => $v('nome'), 'icone' => 'utensils-crossed', 'erro' => $erros['nome'] ?? null, 'classe' => 'col-inteira',
                    'dica' => 'Como aparece no cardápio. Exemplo: X-Burger', 'attrs' => ['maxlength' => 150, 'data-validate' => 'required', 'autocomplete' => 'off']]) ?>
                <fieldset class="opcoes-modo opcoes-unidade col-inteira" id="campo-unidade_venda">
                    <legend>Como você vende</legend>
                    <?php foreach (ProdutoService::UNIDADES_VENDA as $valorUnidade => $rotuloUnidade): ?>
                        <label class="opcao-modo">
                            <input type="radio" name="unidade_venda" value="<?= $e($valorUnidade) ?>"<?= $unidadeVenda === $valorUnidade ? ' checked' : '' ?> data-unidade-venda>
                            <span><strong><?= $e($rotuloUnidade) ?></strong><small><?= $e(['un' => 'Cada venda é 1, 2, 3... (lanche, lata, porção).', 'kg' => 'O preço é o do quilo; no caixa digita o peso (ex.: 0,846 kg).', 'l' => 'O preço é o do litro; no caixa digita o volume (ex.: 0,350 L).'][$valorUnidade]) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <?= Campo::texto(['nome' => 'preco', 'rotulo' => ['un' => 'Preço de venda', 'kg' => 'Preço do quilo', 'l' => 'Preço do litro'][$unidadeVenda], 'valor' => $v('preco'), 'icone' => 'banknote', 'erro' => $erros['preco'] ?? null,
                    'dica' => $porPeso ? 'Exemplo: 59,90 o ' . ($unidadeVenda === 'kg' ? 'quilo' : 'litro') : 'Exemplo: 24,90', 'id' => 'campo-preco',
                    'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'data-validate' => 'required', 'autocomplete' => 'off', 'placeholder' => '0,00', 'data-preco']]) ?>
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
            <div class="produto-foto" id="campo-foto">
                <?php if (!empty($fotoAtual)): ?>
                    <img src="<?= $e(\Cardapim\Shared\MidiaUrl::publica((string) $fotoAtual)) ?>" alt="Foto atual do produto" width="96" height="96" loading="lazy">
                <?php endif; ?>
                <div class="opcao-campo">
                    <label for="foto-produto"><strong><?= empty($fotoAtual) ? 'Foto do produto' : 'Trocar a foto' ?></strong> <span class="field-optional">(opcional)</span></label>
                    <input id="foto-produto" type="file" name="foto" accept="image/png,image/jpeg,image/webp" aria-describedby="foto-dica">
                    <small class="texto-suave" id="foto-dica">PNG, JPG ou WebP, a partir de 300 x 275 pixels e até 5 MB. Aparece no cardápio e no iFood.</small>
                    <?php if (isset($erros['foto'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['foto']) ?></p><?php endif; ?>
                    <?php if (!empty($fotoAtual)): ?>
                        <label class="opcao-tirar"><input type="checkbox" name="tirar_foto" value="1"><span>Tirar a foto</span></label>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if (isset($gruposComplemento) && is_array($gruposComplemento)):
            $marcados = array_map('strval', is_array($valores['grupos'] ?? null) ? $valores['grupos'] : []);
            $ordemGrupo = is_array($valores['ordem_grupo'] ?? null) ? $valores['ordem_grupo'] : [];
        ?>
            <section class="bloco" aria-labelledby="titulo-complementos" id="campo-complementos">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-complementos"><?= Icon::svg('square-plus') ?>Complementos</h2>
                    <p>Escolhas que o cliente faz neste produto (adicionais, borda, molho...). Só para produto vendido por unidade.</p>
                </div>
                <?php if (isset($erros['complementos'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['complementos']) ?></p><?php endif; ?>
                <?php if ($gruposComplemento === []): ?>
                    <p class="texto-suave">Nenhum grupo cadastrado ainda. <a href="<?= $e($base . '/estoque/complementos/novo') ?>">Criar grupo de complementos</a></p>
                <?php else: ?>
                    <fieldset class="produto-grupos">
                        <legend class="sr-only">Grupos de complementos deste produto</legend>
                        <?php foreach ($gruposComplemento as $g): $gid = (string) $g['id']; ?>
                            <div class="produto-grupo">
                                <input type="checkbox" id="grupo-<?= $e($gid) ?>" name="grupos[]" value="<?= $e($gid) ?>"<?= in_array($gid, $marcados, true) ? ' checked' : '' ?>>
                                <label for="grupo-<?= $e($gid) ?>"><span><strong><?= $e($g['nome']) ?></strong>
                                    <small><?= $e(implode(', ', array_map(static fn (array $o): string => $o['nome'], array_slice($g['opcoes'], 0, 6))) . (count($g['opcoes']) > 6 ? '...' : '')) ?></small></span></label>
                                <label class="sr-only" for="ordem-<?= $e($gid) ?>">Posição do grupo <?= $e($g['nome']) ?></label>
                                <input class="campo-simples produto-grupo-ordem" id="ordem-<?= $e($gid) ?>" name="ordem_grupo[<?= $e($gid) ?>]" type="number" min="1" max="99" inputmode="numeric"
                                       value="<?= $e((string) ($ordemGrupo[$gid] ?? $ordemGrupo[(int) $gid] ?? '')) ?>" placeholder="Ordem" title="Ordem em que aparece">
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="texto-suave">Ordem: 1 aparece primeiro. Gerencie os grupos em <a href="<?= $e($base . '/estoque/complementos') ?>">Complementos</a>.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (isset($impressorasProducao) && is_array($impressorasProducao)):
            $impMarcadas = array_map('strval', is_array($valores['impressoras'] ?? null) ? $valores['impressoras'] : []);
        ?>
            <section class="bloco" aria-labelledby="titulo-impressoras" id="campo-impressoras">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-impressoras"><?= Icon::svg('printer') ?>Sai na impressora</h2>
                    <p>Na via de produção, este produto sai só nas impressoras marcadas (pelo apelido do <?= marca_html() ?> Agent). Sem nenhuma marcada, sai na impressora padrão da produção.</p>
                </div>
                <?php if ($impressorasProducao === []): ?>
                    <p class="texto-suave">Nenhuma impressora cadastrada ainda. Cadastre no <?= marca_html() ?> Agent do computador e veja em <a href="<?= $e($base . '/integracoes/impressoras') ?>">Integrações &gt; Impressoras</a>.</p>
                <?php else: ?>
                    <input type="hidden" name="impressoras_enviado" value="1">
                    <fieldset class="produto-grupos produto-impressoras">
                        <legend class="sr-only">Impressoras onde este produto sai</legend>
                        <?php foreach ($impressorasProducao as $imp): $iid = (string) $imp['id']; ?>
                            <div class="produto-grupo">
                                <input type="checkbox" id="impressora-<?= $e($iid) ?>" name="impressoras[]" value="<?= $e($iid) ?>"<?= in_array($iid, $impMarcadas, true) ? ' checked' : '' ?>>
                                <label for="impressora-<?= $e($iid) ?>"><span><strong><?= $e($imp['apelido']) ?></strong><small>Computador: <?= $e($imp['computador']) ?></small></span></label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="texto-suave">Para marcar vários produtos de uma vez, use <a href="<?= $e($base . '/estoque/categorias') ?>">Categorias</a>.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="bloco" aria-labelledby="titulo-peso" data-bloco-peso<?= $porPeso ? '' : ' hidden' ?>>
            <div class="bloco-cabecalho">
                <h2 id="titulo-peso"><?= Icon::svg('scale') ?><span>Venda por <span data-nome-unidade><?= $unidadeVenda === 'l' ? 'litro' : 'quilo' ?></span></span></h2>
                <p>No caixa e no garçom a equipe digita o peso da balança. No cardápio online o cliente escolhe uma das porções abaixo.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'venda_minima', 'rotulo' => 'Quantidade mínima por venda', 'valor' => $v('venda_minima'), 'icone' => 'scale', 'erro' => $erros['venda_minima'] ?? null, 'opcional' => true,
                    'dica' => 'Evita lançar um peso pequeno por engano. Exemplo: 0,100 (100 gramas).', 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'peso', 'autocomplete' => 'off', 'placeholder' => '0,000']]) ?>
            </div>
            <fieldset class="porcoes" id="campo-porcoes">
                <legend>Porções do cardápio online <span class="field-optional">(opcional)</span></legend>
                <p class="ficha-ajuda">Sem porção, o produto só é vendido no caixa e no garçom. Exemplo: 0,250 · 0,500 · 1,000. O preço de cada uma sai do preço do <span data-nome-unidade><?= $unidadeVenda === 'l' ? 'litro' : 'quilo' ?></span>.</p>
                <?php if (isset($erros['porcoes'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['porcoes']) ?></p><?php endif; ?>
                <div class="porcoes-grade">
                    <?php foreach ($porcoesValores as $i => $porcao): ?>
                        <label class="porcao-campo">
                            <span class="sr-only">Porção <?= $i + 1 ?></span>
                            <input name="porcoes[]" value="<?= $e($porcao) ?>" inputmode="numeric" autocomplete="off" placeholder="0,000" data-mask="peso" data-porcao>
                            <span class="porcao-sigla" data-sigla-unidade><?= $unidadeVenda === 'l' ? 'L' : 'kg' ?></span>
                            <small class="porcao-preco" data-porcao-preco aria-live="polite"></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </section>

        <section class="bloco" aria-labelledby="titulo-estoque">
            <div class="bloco-cabecalho">
                <h2 id="titulo-estoque"><?= Icon::svg('package') ?>Estoque deste produto</h2>
                <p>Escolha como cada venda mexe no estoque.</p>
            </div>
            <?php if (isset($erros['controle'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['controle']) ?></p><?php endif; ?>
            <fieldset class="opcoes-modo">
                <legend class="sr-only">Como controlar o estoque</legend>
                <label class="opcao-modo">
                    <input type="radio" name="controle" value="estoque"<?= $controle === 'estoque' ? ' checked' : '' ?> data-controle>
                    <span><strong>Baixar dos insumos (ficha técnica)</strong><small>Para o que você prepara: pizza, lanche, açaí. Cada venda tira os insumos da ficha abaixo; se um acabar, o produto sai de venda sozinho.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="controle" value="proprio"<?= $controle === 'proprio' ? ' checked' : '' ?> data-controle>
                    <span><strong>Contar as unidades deste produto</strong><small>Para o que você compra pronto e revende: refrigerante, água, cerveja lata. Cada venda tira 1 e, quando zerar, sai de venda sozinho.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="controle" value="livre"<?= $controle === 'livre' ? ' checked' : '' ?> data-controle>
                    <span><strong>Não controlar estoque</strong><small>O produto fica sempre à venda, até você pausar.</small></span>
                </label>
            </fieldset>

            <div class="ficha-area proprio-area" data-proprio-area<?= $controle === 'proprio' ? '' : ' hidden' ?>>
                <input type="hidden" name="estoque_quantidade_antes" value="<?= $e($v('estoque_quantidade_antes')) ?>">
                <div class="proprio-campos">
                    <?= Campo::texto(['nome' => 'estoque_quantidade', 'rotulo' => 'Quanto tem agora', 'valor' => $v('estoque_quantidade'), 'icone' => 'package',
                        'erro' => $erros['estoque_quantidade'] ?? null, 'sufixo' => $siglaVenda[$unidadeVenda], 'dica' => 'O que você tem hoje.',
                        // Nao e "required" no HTML: o campo fica escondido nas outras opcoes; o servidor confere.
                        'attrs' => ['inputmode' => 'decimal', 'data-mask' => 'quantity', 'placeholder' => 'Ex.: 10', 'autocomplete' => 'off', 'required' => false]]) ?>
                    <?= Campo::texto(['nome' => 'estoque_minimo_produto', 'rotulo' => 'Avisar quando chegar a', 'valor' => $v('estoque_minimo_produto'), 'icone' => 'bell',
                        'erro' => $erros['estoque_minimo_produto'] ?? null, 'opcional' => true, 'sufixo' => $siglaVenda[$unidadeVenda], 'dica' => 'Entra em "Precisa comprar".',
                        'attrs' => ['inputmode' => 'decimal', 'data-mask' => 'quantity', 'placeholder' => 'Ex.: 3', 'autocomplete' => 'off']]) ?>
                    <?= Campo::texto(['nome' => 'estoque_custo', 'rotulo' => 'Custo por unidade', 'valor' => $v('estoque_custo'), 'icone' => 'banknote',
                        'erro' => $erros['estoque_custo'] ?? null, 'opcional' => true, 'dica' => 'Mostra quanto sobra em cada venda.',
                        'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00', 'autocomplete' => 'off']]) ?>
                </div>
                <p class="ficha-ajuda">Chegou mais? Use <strong>Somar</strong> na lista de produtos ou em <strong>Precisa comprar</strong>, no resumo do estoque.</p>
            </div>

            <div class="ficha-area" data-ficha-area<?= $controle === 'estoque' ? '' : ' hidden' ?>>
                <h3 class="ficha-titulo" id="campo-ficha">Ficha técnica: o que vai em <strong data-ficha-unidade-venda><?= $e($nomeUnidade) ?></strong> do produto</h3>
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
