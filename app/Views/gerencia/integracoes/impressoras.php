<?php
/**
 * Impressoras do Deliveryzim Agent: impressao por produto (padrao), cupom completo e resumo do caixa.
 * Espera: $ativo, $porProduto, $impressoras (ImpressoraRepository::listar), $produtosPorImpressora, $padraoProducao,
 * $completo e $fechamento (ids/padrao), $produtosTeste (com grupos/combo), $logoCupom, $agentDownload, $base, $csrf,
 * $formatos (papel informado pelo Agent, por id), $aparencia (null = indisponivel), $aparenciaOpcoes, $aparenciaPadrao.
 */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageStyles = [...($pageStyles ?? []), 'css/impressoras.css', 'css/impressao-teste.css'];
$pageScripts = [...($pageScripts ?? []), 'js/complementos.js', 'js/impressao-teste.js', 'js/cupom-aparencia.js'];
$formatos = $formatos ?? [];
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
                        <?php $fmt = $formatos[$imp['id']] ?? null; ?>
                        <span class="impressoras-nome"><strong><?= $e($imp['apelido']) ?></strong><small>Computador: <?= $e($imp['computador']) ?><?php if ($porProduto): $n = (int) ($produtosPorImpressora[$imp['id']] ?? 0); ?> · <?= $n === 1 ? '1 produto' : $n . ' produtos' ?><?php endif; ?></small>
                            <small class="impressoras-papel"><?= Icon::svg('receipt', 'icon-sm') ?><?php if ($fmt !== null && $fmt['informado']): ?>Papel <?= (int) $fmt['papel'] ?> mm (informado pelo Agent)<?php else: ?>Papel não informado: usando 80 mm. Atualize o Agent para a versão 0.9 ou mais nova.<?php endif; ?></small></span>
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
                <?php if (isset($cancelamentoAtivo) && $cancelamentoAtivo !== null): ?>
                    <fieldset class="bloco impressao-ponto">
                        <legend><?= Icon::svg('circle-x') ?>Aviso de cancelamento</legend>
                        <input type="hidden" name="cancelamento_enviado" value="1">
                        <label class="alternador">
                            <span><strong>Imprimir quando um item ou comanda for cancelado</strong>
                                <small>Sai "CANCELAMENTO" na impressora de cada item (ex.: na Cozinha: "1x Picanha da Mesa 5 foi cancelado"), com o motivo. Só para itens que já tinham sido impressos. Vale para caixa, garçom e delivery.</small></span>
                            <input type="checkbox" name="cancelamento" value="1"<?= $cancelamentoAtivo ? ' checked' : '' ?>><span class="alternador-chave" aria-hidden="true"></span>
                        </label>
                    </fieldset>
                <?php endif; ?>
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

        <?php if ($aparencia !== null):
            $pesosPorFonte = [];
            foreach ($aparenciaOpcoes['fontes'] as $f) { $pesosPorFonte[$f['id']] = $f['pesos']; }
            $pesoOpcoes = static function (string $nome, int $atual, array $pesos) use ($e): void {
                foreach ($pesos as $p): ?><option value="<?= (int) $p['valor'] ?>"<?= $p['valor'] === $atual ? ' selected' : '' ?>><?= $e($p['rotulo']) ?></option><?php endforeach;
            };
            $nomePadrao = '';
            foreach ($aparenciaOpcoes['fontes'] as $f) { if ($f['id'] === $aparenciaPadrao['fonte']) { $nomePadrao = $f['nome']; } }
        ?>
        <section class="bloco cupom-aparencia" id="aparencia-cupom" aria-labelledby="titulo-aparencia" data-cupom-aparencia
                 data-previa="<?= $e($base . '/integracoes/impressoras/aparencia/previa') ?>"
                 data-pesos="<?= $e((string) json_encode($pesosPorFonte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                 data-padrao="<?= $e((string) json_encode($aparenciaPadrao, JSON_UNESCAPED_SLASHES)) ?>"
                 data-fraco="<?= (int) \Cardapim\Services\Impressao\AparenciaCupom::PESO_FRACO ?>">
            <div class="bloco-cabecalho">
                <h2 id="titulo-aparencia"><?= Icon::svg('type') ?>Aparência do cupom</h2>
                <p>Escolha a letra dos cupons deste restaurante. A prévia ao lado mostra na hora como sai no papel. Vale para o <?= marca_html() ?> Agent 0.9 ou mais novo.</p>
            </div>
            <div class="cupom-aparencia-grade">
                <form method="post" action="<?= $e($base . '/integracoes/impressoras/aparencia') ?>" class="cupom-aparencia-form" data-aparencia-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <p class="cupom-aparencia-status">
                        <?php if ($aparencia['personalizada']): ?>
                            <span class="etiqueta etiqueta-ok"><?= Icon::svg('palette') ?>Personalizado</span>
                        <?php else: ?>
                            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('circle-check') ?>Padrão do sistema</span>
                        <?php endif; ?>
                        <small>Padrão: <?= $e($nomePadrao) ?>, texto Regular, destaques em Negrito, letra Normal.</small>
                    </p>
                    <fieldset class="cupom-aparencia-chips">
                        <legend>Fonte</legend>
                        <?php foreach ($aparenciaOpcoes['fontes'] as $f): ?>
                            <label class="chip-opcao"><input type="radio" name="fonte" value="<?= $e($f['id']) ?>"<?= $aparencia['fonte'] === $f['id'] ? ' checked' : '' ?>><span><?= $e($f['nome']) ?><?= $f['id'] === $aparenciaPadrao['fonte'] ? ' (padrão)' : '' ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset class="cupom-aparencia-chips">
                        <legend>Tamanho da letra</legend>
                        <?php foreach ($aparenciaOpcoes['tamanhos'] as $t): ?>
                            <label class="chip-opcao"><input type="radio" name="tamanho" value="<?= $e($t['id']) ?>"<?= $aparencia['tamanho'] === $t['id'] ? ' checked' : '' ?>><span><?= $e($t['rotulo']) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="cupom-aparencia-pesos">
                        <label class="impressao-teste-campo"><span>Peso do texto</span>
                            <select name="peso_texto" data-peso="texto"><?php $pesoOpcoes('peso_texto', $aparencia['peso_texto'], $pesosPorFonte[$aparencia['fonte']]); ?></select>
                        </label>
                        <label class="impressao-teste-campo"><span>Peso dos destaques <small>(itens, títulos e total)</small></span>
                            <select name="peso_destaque" data-peso="destaque"><?php $pesoOpcoes('peso_destaque', $aparencia['peso_destaque'], $pesosPorFonte[$aparencia['fonte']]); ?></select>
                        </label>
                    </div>
                    <p class="cupom-aparencia-aviso" data-aparencia-aviso role="status" hidden><?= Icon::svg('info', 'icon-sm') ?>Pesos finos (Fina, Extra fina e Leve) podem sair apagados na impressora térmica. Confira com um teste.</p>
                    <div class="cupom-aparencia-acoes">
                        <button class="btn btn-primary" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar aparência</button>
                        <?php if ($aparencia['personalizada']): ?>
                            <button class="btn btn-outline" type="submit" form="form-aparencia-padrao"><?= Icon::svg('rotate-ccw', 'icon-sm') ?>Voltar ao padrão</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($aparencia['personalizada']): ?>
                    <form method="post" action="<?= $e($base . '/integracoes/impressoras/aparencia') ?>" id="form-aparencia-padrao" hidden
                          data-confirm="A sua personalização será apagada e os cupons voltam ao padrão do sistema." data-confirm-titulo="Voltar ao padrão?">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="acao" value="padrao">
                    </form>
                <?php endif; ?>
                <figure class="cupom-aparencia-previa" aria-live="polite">
                    <div class="cupom-aparencia-papel" role="group" aria-label="Largura do papel da prévia">
                        <button type="button" class="chip-mini is-ativo" data-previa-papel="80" aria-pressed="true">80 mm</button>
                        <button type="button" class="chip-mini" data-previa-papel="58" aria-pressed="false">58 mm</button>
                    </div>
                    <div class="cupom-aparencia-quadro" data-previa-quadro>
                        <img class="cupom-imagem" data-previa-imagem alt="Prévia do cupom com a aparência escolhida" hidden>
                        <p class="cupom-aparencia-carregando" data-previa-carregando><?= Icon::svg('loader-circle', 'icon-sm') ?>Montando a prévia...</p>
                    </div>
                    <figcaption data-previa-legenda>Prévia com um pedido de exemplo.</figcaption>
                </figure>
            </div>
        </section>
        <?php endif; ?>

        <section class="bloco impressao-teste" aria-labelledby="titulo-teste" data-impressao-teste data-logo="<?= $e($logoCupom) ?>">
            <div class="bloco-cabecalho">
                <h2 id="titulo-teste"><?= Icon::svg('flask-conical') ?>Testar impressão</h2>
                <p>Monte um pedido com seus produtos e veja o que sai em cada impressora, e por quê. Nada é gravado. Salve as mudanças acima antes de testar.</p>
            </div>
            <form method="post" action="<?= $e($base . '/integracoes/impressoras/teste') ?>" class="impressao-teste-form" data-teste-form novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <fieldset class="impressao-teste-situacao">
                    <legend>1. Situação</legend>
                    <?php foreach (['delivery' => ['bike', 'Delivery aceito'], 'garcom' => ['hand-platter', 'Garçom lança'], 'caixa' => ['monitor', 'Caixa: Novos itens'], 'cancelamento' => ['circle-x', 'Item cancelado'], 'conta' => ['receipt', 'Conta (cupom completo)']] as $valor => [$icone, $rotulo]): ?>
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
                                        <option value="<?= (int) $pt['id'] ?>"<?php if ($pt['grupos'] !== []): ?> data-grupos="<?= $e((string) json_encode($pt['grupos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"<?php endif; ?><?php if ($pt['combo'] !== null): ?> data-combo="<?= $e((string) json_encode($pt['combo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"<?php endif; ?>><?= $e($pt['nome']) ?><?= $pt['combo'] !== null ? ' (combo)' : ($pt['grupos'] !== [] ? ' (com escolhas)' : '') ?></option>
                                    <?php endforeach; ?></optgroup>
                                </select>
                            </label>
                            <label class="impressao-teste-campo impressao-teste-qtd"><span>Qtd.</span><input type="number" min="1" max="99" value="1" inputmode="numeric" data-teste-qtd></label>
                            <label class="impressao-teste-campo impressao-teste-obs"><span>Observação (opcional)</span><input type="text" maxlength="140" placeholder="Ex.: sem cebola, bem passado" data-teste-obs></label>
                            <button class="btn btn-outline" type="button" data-teste-adicionar><?= Icon::svg('plus', 'icon-sm') ?>Adicionar</button>
                        </div>
                        <div class="impressao-teste-escolhas" data-teste-escolhas hidden></div>
                        <p class="impressao-teste-erro" data-teste-escolhas-erro role="alert" hidden></p>
                    <?php endif; ?>
                    <ul class="impressao-teste-itens" data-teste-itens></ul>
                    <p class="impressao-teste-vazio" data-teste-vazio>Nenhum produto no teste ainda. Escolha um produto e toque em Adicionar.</p>
                </fieldset>

                <div class="impressao-teste-acoes">
                    <button class="btn btn-primary" type="submit" data-teste-ver><?= Icon::svg('eye', 'icon-sm') ?>Ver como sai</button>
                    <button class="btn btn-outline" type="button" data-teste-imprimir><?= Icon::svg('printer', 'icon-sm') ?>Imprimir teste de verdade</button>
                </div>
            </form>
            <div class="impressao-teste-resultado" data-teste-resultado aria-live="polite"></div>
        </section>
    <?php endif; ?>
    <?php require dirname(__DIR__) . '/fiscal/_impressora.php'; ?>
</div>
