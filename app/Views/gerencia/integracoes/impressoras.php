<?php
/**
 * Impressoras do Deliveryzim Agent: impressao por produto (padrao), cupom completo e resumo do caixa.
 * Espera: $ativo, $porProduto, $impressoras (ImpressoraRepository::listar), $produtosPorImpressora, $padraoProducao,
 * $completo e $fechamento (ids/padrao), $agentDownload, $base, $csrf.
 */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageStyles = [...($pageStyles ?? []), 'css/impressoras.css'];
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
    <?php endif; ?>
</div>
