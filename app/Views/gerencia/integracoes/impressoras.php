<?php
/**
 * Impressoras do Deliveryzim Agent e pontos de impressao.
 * Espera: $ativo, $impressoras (ImpressoraRepository::listar), $pontos (ponto => ids/padrao), $rotulos, $agentDownload, $base, $csrf.
 */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ajuda = [
    'caixa_conta' => 'Botão "Imprimir conta" na comanda do caixa.',
    'caixa_delivery' => 'Botão "Imprimir" no pedido do delivery, no caixa.',
    'caixa_fechamento' => 'Botão "Imprimir" no resumo do caixa.',
    'garcom' => 'Botão "Imprimir" na comanda do app do garçom.',
    'delivery_novo' => 'Via completa quando chega pedido do cardápio ou do iFood. Sai em TODAS as impressoras marcadas (ex.: Balcão).',
    'producao' => 'Liga as vias de produção: "Só os itens novos" (garçom e caixa) e o pedido do delivery aceito saem divididos, cada item na impressora marcada no produto (Estoque > produto ou Categorias). Itens sem impressora marcada saem na padrão.',
];
$pageStyles = [...($pageStyles ?? []), 'css/impressoras.css'];
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>Impressoras</h1>
            <p>Escolha em qual impressora cada parte do sistema imprime. Com uma impressora marcada, imprime direto; com duas ou mais, quem imprime escolhe na hora (a padrão já vem selecionada).</p>
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
                        <span class="impressoras-nome"><strong><?= $e($imp['apelido']) ?></strong><small>Computador: <?= $e($imp['computador']) ?></small></span>
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
            <?php foreach ($rotulos as $ponto => $rotulo): $cfg = $pontos[$ponto] ?? ['ids' => [], 'padrao' => null]; $automatico = $ponto === 'delivery_novo'; ?>
                <fieldset class="bloco impressao-ponto">
                    <legend><?= Icon::svg($automatico ? 'bell' : ($ponto === 'garcom' ? 'hand-platter' : ($ponto === 'producao' ? 'chef-hat' : 'monitor'))) ?><?= $e($rotulo) ?></legend>
                    <p class="impressao-ponto-ajuda"><?= $e($ajuda[$ponto]) ?></p>
                    <ul class="impressao-ponto-lista">
                        <?php foreach ($impressoras as $imp): $marcada = in_array($imp['id'], $cfg['ids'], true); $idCampo = 'p-' . $ponto . '-' . $imp['id']; ?>
                            <li>
                                <label class="impressao-ponto-usar" for="<?= $e($idCampo) ?>">
                                    <input type="checkbox" id="<?= $e($idCampo) ?>" name="ponto[<?= $e($ponto) ?>][]" value="<?= (int) $imp['id'] ?>"<?= $marcada ? ' checked' : '' ?>>
                                    <span><?= $e($imp['apelido']) ?></span>
                                </label>
                                <?php if (!$automatico): ?>
                                    <label class="impressao-ponto-padrao"><input type="radio" name="padrao[<?= $e($ponto) ?>]" value="<?= (int) $imp['id'] ?>"<?= $cfg['padrao'] === $imp['id'] ? ' checked' : '' ?>><span>Padrão</span></label>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="impressao-ponto-vazio"><?= $ponto === 'producao' ? 'Nenhuma marcada: vias de produção desligadas ("Só os itens novos" sai inteiro na impressora escolhida).' : 'Nenhuma marcada: imprime na impressora principal do Agent.' ?></p>
                </fieldset>
            <?php endforeach; ?>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar pontos de impressão</button>
            </div>
        </form>
    <?php endif; ?>
</div>
