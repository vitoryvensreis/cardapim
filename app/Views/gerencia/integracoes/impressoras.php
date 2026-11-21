<?php
/**
 * Impressoras do Deliveryzim Agent: impressao por produto (padrao), cupom completo e resumo do caixa.
 * Espera: $ativo, $porProduto, $impressoras (ImpressoraRepository::listar), $produtosPorImpressora, $padraoProducao,
 * $completo e $fechamento (ids/padrao), $produtosTeste, $agentDownload, $base, $csrf.
 */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageStyles = [...($pageStyles ?? []), 'css/impressoras.css', 'css/impressao-teste.css'];
$pageScripts = [...($pageScripts ?? []), 'js/impressao-teste.js'];
// Lista de impressoras com "Padrao" (cupom completo e resumo do caixa).
$lista = static function (string $campo, array $cfg, array $impressoras) use ($e): void { ?>
    <ul class="impressao-ponto-lista">
        <?php foreach ($impressoras as $imp): $id = 'p-' . $campo . '-' . $imp['id']; ?>
            <li>
                <label class="impressao-ponto-usar" for="<?= $e($id) ?>">
                    <input type="checkbox" id="<?= $e($id) ?>" name="<?= $e($campo) ?>[]" value="<?= (int) $imp['id'] ?>"<?= in_array($imp['id'], $cfg['ids'], true) ? ' checked' : '' ?>>
                    <span><?= $e($imp['apelido']) ?></span>
                </label>
                <label class="impressao-ponto-padrao"><input type="radio" name="padrao_<?= $e($campo) ?>" value="<?= (int) $imp['id'] ?>"<?= $cfg['padrao'] === $imp['id'] ? ' checked' : '' ?>><span>Padrão</span></label>
            </li>
        <?php endforeach; ?>
    </ul>
<?php };
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>Impressoras</h1>
            <p>Os itens saem sozinhos na impressora marcada em cada produto. A conta e a comanda inteira saem completas na impressora que você escolher.</p>
        </div>
    </header>

    <?php if (!$ativo): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('clock') ?><strong>Em ativação</strong><p>As impressoras ficam disponíveis em alguns minutos.</p></div></section>
    <?php elseif ($impressoras === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('printer') ?>
                <strong>Nenhuma impressora cadastrada ainda</strong>
                <p>No computador da impressora, abra o <?= marca_html() ?> Agent (versão 0.8 ou mais nova), clique em <strong>Adicionar impressora</strong> e dê um apelido, como "Balcão" ou "Cozinha". Ela aparece aqui sozinha.</p>
                <p>Enquanto isso, tudo imprime na impressora principal do Agent.</p>
                <a class="btn btn-primary btn-sm" href="<?= $e((string) $agentDownload) ?>" rel="noopener" download><?= Icon::svg('download', 'icon-sm') ?>Baixar o Agent para Windows</a>
            </div>
        </section>
    <?php else: ?>
        <section class="bloco" aria-labelledby="titulo-impressoras">
            <div class="bloco-cabecalho">
                <h2 id="titulo-impressoras"><?= Icon::svg('printer') ?>Impressoras cadastradas</h2>
                <p>Para incluir, trocar o apelido ou tirar uma impressora, use o <?= marca_html() ?> Agent do computador dela.</p>
            </div>
            <ul class="impressoras-lista">
                <?php foreach ($impressoras as $imp): ?>
                    <li>
                        <span class="impressoras-nome"><strong><?= $e($imp['apelido']) ?></strong><small>Computador: <?= $e($imp['computador']) ?><?php if ($porProduto): $n = (int) ($produtosPorImpressora[$imp['id']] ?? 0); ?> · <?= $n === 1 ? '1 produto' : $n . ' produtos' ?><?php endif; ?></small></span>
                        <?php if ($imp['ligada']): ?>
                            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Ligada</span>
                        <?php else: ?>
                            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('circle-x') ?>Desligada</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <form method="post" action="<?= $e($base . '/integracoes/impressoras') ?>" class="impressao-pontos">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?php if ($porProduto): ?>
                <fieldset class="bloco impressao-ponto">
                    <legend><?= Icon::svg('chef-hat') ?>Impressão por produto</legend>
                    <p class="impressao-ponto-ajuda">Cada item sai sozinho na impressora marcada no produto, só com os itens daquela impressora:</p>
                    <ul class="impressao-momentos">
                        <li><?= Icon::svg('bike', 'icon-sm') ?>Delivery: quando o pedido é aceito</li>
                        <li><?= Icon::svg('hand-platter', 'icon-sm') ?>Garçom: quando toca em Lançar</li>
                        <li><?= Icon::svg('monitor', 'icon-sm') ?>Caixa: quando toca em Novos itens</li>
                    </ul>
                    <p class="impressao-ponto-titulo">Impressora padrão <small>para produtos sem impressora marcada</small></p>
                    <ul class="impressao-ponto-lista">
                        <?php foreach ($impressoras as $imp): ?>
                            <li>
                                <label class="impressao-ponto-usar" for="p-producao-<?= (int) $imp['id'] ?>">
                                    <input type="radio" id="p-producao-<?= (int) $imp['id'] ?>" name="producao" value="<?= (int) $imp['id'] ?>"<?= $padraoProducao === $imp['id'] ? ' checked' : '' ?>>
                                    <span><?= $e($imp['apelido']) ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                        <li>
                            <label class="impressao-ponto-usar" for="p-producao-0">
                                <input type="radio" id="p-producao-0" name="producao" value="0"<?= $padraoProducao === null ? ' checked' : '' ?>>
                                <span>Desligada <small>não imprime nada sozinho</small></span>
                            </label>
                        </li>
                    </ul>
                    <a class="impressao-link" href="<?= $e($base . '/estoque/categorias') ?>"><?= Icon::svg('tags', 'icon-sm') ?>Marcar a impressora dos produtos por categoria</a>
                </fieldset>
            <?php endif; ?>

            <fieldset class="bloco impressao-ponto">
                <legend><?= Icon::svg('receipt') ?>Cupom completo</legend>
                <p class="impressao-ponto-ajuda">Botões "Conta" do caixa, "Comanda inteira" do garçom e "Imprimir" do pedido do delivery. Saem com todos os itens. Com duas ou mais marcadas, quem imprime escolhe na hora (a padrão já vem selecionada).</p>
                <?php $lista('completo', $completo, $impressoras); ?>
                <p class="impressao-ponto-vazio">Nenhuma marcada: imprime na impressora principal do Agent.</p>
            </fieldset>

            <fieldset class="bloco impressao-ponto">
                <legend><?= Icon::svg('wallet') ?>Resumo do caixa</legend>
                <p class="impressao-ponto-ajuda">Botão "Imprimir" no resumo e no fechamento do caixa.</p>
                <?php $lista('fechamento', $fechamento, $impressoras); ?>
                <p class="impressao-ponto-vazio">Nenhuma marcada: imprime na impressora principal do Agent.</p>
            </fieldset>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar impressoras</button>
            </div>
        </form>

        <section class="bloco impressao-teste" aria-labelledby="titulo-teste" data-impressao-teste>
            <div class="bloco-cabecalho">
                <h2 id="titulo-teste"><?= Icon::svg('flask-conical') ?>Testar impressão</h2>
                <p>Monte um pedido com seus produtos e veja o que sai em cada impressora, e por quê. Nada é gravado. Salve as mudanças acima antes de testar.</p>
            </div>
            <form method="post" action="<?= $e($base . '/integracoes/impressoras/teste') ?>" class="impressao-teste-form" data-teste-form novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <fieldset class="impressao-teste-situacao">
                    <legend>1. Situação</legend>
                    <?php foreach (['delivery' => ['bike', 'Delivery aceito'], 'garcom' => ['hand-platter', 'Garçom lança'], 'caixa' => ['monitor', 'Caixa: Novos itens'], 'conta' => ['receipt', 'Conta (cupom completo)']] as $valor => [$icone, $rotulo]): ?>
                        <label class="chip-opcao"><input type="radio" name="situacao" value="<?= $e($valor) ?>"<?= $valor === 'delivery' ? ' checked' : '' ?>><span><?= Icon::svg($icone, 'icon-sm') ?><?= $e($rotulo) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="impressao-teste-campo" data-teste-conta hidden>
                    <span>Imprimir a conta em</span>
                    <select name="impressora">
                        <?php $temCompleto = false; foreach ($impressoras as $imp): if (!in_array($imp['id'], $completo['ids'], true)) { continue; } $temCompleto = true; ?>
                            <option value="<?= (int) $imp['id'] ?>"<?= $completo['padrao'] === $imp['id'] ? ' selected' : '' ?>><?= $e($imp['apelido']) ?></option>
                        <?php endforeach; ?>
                        <?php if (!$temCompleto): ?><option value="">Impressora principal do Agent</option><?php endif; ?>
                    </select>
                </label>

                <fieldset class="impressao-teste-adicionar">
                    <legend>2. Produtos do teste</legend>
                    <?php if ($produtosTeste === []): ?>
                        <p class="impressao-ponto-ajuda">Cadastre produtos no Estoque para testar.</p>
                    <?php else: ?>
                        <div class="impressao-teste-linha">
                            <label class="impressao-teste-campo impressao-teste-produto"><span>Produto</span>
                                <select data-teste-produto>
                                    <?php $grupo = null; foreach ($produtosTeste as $pt): if ($pt['categoria'] !== $grupo): ?>
                                        <?php if ($grupo !== null): ?></optgroup><?php endif; $grupo = $pt['categoria']; ?><optgroup label="<?= $e($grupo) ?>">
                                    <?php endif; ?>
                                        <option value="<?= (int) $pt['id'] ?>"><?= $e($pt['nome']) ?></option>
                                    <?php endforeach; ?></optgroup>
                                </select>
                            </label>
                            <label class="impressao-teste-campo impressao-teste-qtd"><span>Qtd.</span><input type="number" min="1" max="99" value="1" inputmode="numeric" data-teste-qtd></label>
                            <label class="impressao-teste-campo impressao-teste-obs"><span>Observação (opcional)</span><input type="text" maxlength="140" placeholder="Ex.: sem cebola, bem passado" data-teste-obs></label>
                            <button class="btn btn-outline" type="button" data-teste-adicionar><?= Icon::svg('plus', 'icon-sm') ?>Adicionar</button>
                        </div>
                    <?php endif; ?>
                    <ul class="impressao-teste-itens" data-teste-itens></ul>
                    <p class="impressao-teste-vazio" data-teste-vazio>Nenhum produto no teste ainda. Escolha um produto e toque em Adicionar.</p>
                </fieldset>

                <label class="impressao-teste-campo impressao-teste-papel"><span>3. Largura do papel na tela</span>
                    <select data-teste-papel>
                        <option value="48">80 mm (48 letras por linha)</option>
                        <option value="42">80 mm compacta (42 letras por linha)</option>
                        <option value="32">58 mm (32 letras por linha)</option>
                    </select>
                </label>

                <div class="impressao-teste-acoes">
                    <button class="btn btn-primary" type="submit" data-teste-ver><?= Icon::svg('eye', 'icon-sm') ?>Ver como sai</button>
                    <button class="btn btn-outline" type="button" data-teste-imprimir><?= Icon::svg('printer', 'icon-sm') ?>Imprimir teste de verdade</button>
                </div>
            </form>
            <div class="impressao-teste-resultado" data-teste-resultado aria-live="polite"></div>
        </section>
    <?php endif; ?>
</div>
