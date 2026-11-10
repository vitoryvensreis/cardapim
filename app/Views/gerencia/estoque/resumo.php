<?php
/**
 * Resumo do estoque: o que acabou, o que esta acabando e o que nao pode ser vendido.
 * Espera: $analise (AnaliseEstoque::analisar), $base, $csrf, $contagem, $abaAtual.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$comprar = [...$analise['emFalta'], ...$analise['baixos']];
$vazio = $analise['totalInsumos'] === 0 && $analise['totalProdutos'] === 0;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Estoque</h1>
            <p>Veja o que acabou, o que está acabando e o que precisa comprar.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/estoque/insumos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo insumo</a>
            <a class="btn btn-primary btn-sm" href="<?= $e($base . '/estoque/produtos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo produto</a>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>

    <?php if ($vazio): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('package') ?>
                <strong>Seu estoque ainda está vazio</strong>
                <p>Comece pelos insumos (pão, carne, bebidas...). Depois cadastre os produtos do cardápio e diga quanto de cada insumo vai neles.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary" href="<?= $e($base . '/estoque/insumos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar o primeiro insumo</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="indicadores">
            <div class="indicador <?= count($analise['emFalta']) > 0 ? 'is-alerta' : 'is-ok' ?>">
                <span class="indicador-topo"><?= Icon::svg('package-x') ?>Acabaram</span>
                <strong><?= count($analise['emFalta']) ?></strong>
                <small><?= count($analise['emFalta']) === 1 ? 'insumo sem estoque' : 'insumos sem estoque' ?></small>
            </div>
            <div class="indicador <?= count($analise['baixos']) > 0 ? 'is-atencao' : 'is-ok' ?>">
                <span class="indicador-topo"><?= Icon::svg('triangle-exclamation-point') ?>Estoque baixo</span>
                <strong><?= count($analise['baixos']) ?></strong>
                <small>no mínimo ou abaixo dele</small>
            </div>
            <div class="indicador <?= count($analise['indisponiveis']) > 0 ? 'is-alerta' : 'is-ok' ?>">
                <span class="indicador-topo"><?= Icon::svg('ban') ?>Fora de venda</span>
                <strong><?= count($analise['indisponiveis']) ?></strong>
                <small>de <?= (int) $analise['totalProdutos'] ?> produtos do cardápio</small>
            </div>
            <div class="indicador">
                <span class="indicador-topo"><?= Icon::svg('wallet') ?>Valor em estoque</span>
                <strong><?= $e(Numero::reais(number_format($analise['valorEstoqueCentavos'] / 100, 2, '.', ''))) ?></strong>
                <small><?= $analise['insumosSemCusto'] > 0 ? $e($analise['insumosSemCusto'] . ' insumo(s) sem custo informado') : 'pelo custo dos insumos' ?></small>
            </div>
        </div>

        <section class="bloco" aria-labelledby="titulo-comprar">
            <div class="bloco-cabecalho">
                <h2 id="titulo-comprar"><?= Icon::svg('shopping-bag') ?>Precisa comprar</h2>
                <p>Chegou mercadoria? Digite quanto chegou e toque em <strong>Adicionar</strong>. O valor é somado ao que já tem.</p>
            </div>
            <?php if ($comprar === []): ?>
                <div class="lista-vazia">
                    <?= Icon::svg('circle-check') ?>
                    <strong>Tudo certo por aqui</strong>
                    <p>Nenhum insumo acabou ou está abaixo do mínimo.</p>
                </div>
            <?php else: ?>
                <ul class="lista lista-comprar">
                    <li class="lista-cabecalho" aria-hidden="true"><span>Insumo</span><span>Quanto tem</span><span>Chegou mercadoria?</span></li>
                    <?php foreach ($comprar as $insumo):
                        $a = $insumo['analise'];
                        $u = UnidadeMedida::from((string) $insumo['unidade']);
                        $falta = $a['situacao'] === AnaliseEstoque::FALTA;
                    ?>
                        <li class="lista-item">
                            <div class="lista-principal">
                                <span class="lista-icone" aria-hidden="true"><?= Icon::svg($falta ? 'package-x' : 'triangle-exclamation-point', 'icon-sm') ?></span>
                                <span class="lista-titulo">
                                    <a href="<?= $e($base . '/estoque/insumos/' . (int) $insumo['id']) ?>"><strong><?= $e((string) $insumo['nome']) ?></strong></a>
                                    <small><?= $falta ? 'Acabou' : 'Faltam ' . $e($u->formatar($a['faltaParaMinimo'])) . ' para o mínimo' ?></small>
                                </span>
                            </div>
                            <div class="nivel">
                                <span class="etiqueta <?= $falta ? 'etiqueta-alerta' : 'etiqueta-atencao' ?>"><?= $falta ? 'Em falta' : 'Baixo' ?>: <?= $e($u->formatar($a['atual'])) ?></span>
                                <span class="nivel-barra" aria-hidden="true"><span class="n-<?= (int) $a['nivel'] ?>"></span></span>
                                <small class="nivel-legenda">Mínimo: <?= $e($u->formatar($a['minimo'])) ?></small>
                            </div>
                            <form class="repor" method="post" action="<?= $e($base . '/estoque/insumos/' . (int) $insumo['id'] . '/repor') ?>">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <label class="sr-only" for="repor-<?= (int) $insumo['id'] ?>">Quanto chegou de <?= $e((string) $insumo['nome']) ?></label>
                                <span class="repor-campo">
                                    <input id="repor-<?= (int) $insumo['id'] ?>" name="quantidade" inputmode="decimal" autocomplete="off" placeholder="Quanto chegou" data-mask="quantity" required>
                                    <span aria-hidden="true"><?= $e($u->sigla()) ?></span>
                                </span>
                                <button class="btn btn-wine btn-sm" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Adicionar</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <div class="blocos-duplos">
            <section class="bloco" aria-labelledby="titulo-fora">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-fora"><?= Icon::svg('ban') ?>Fora de venda</h2>
                    <p>Produtos que o cliente não consegue pedir agora.</p>
                </div>
                <?php if ($analise['indisponiveis'] === []): ?>
                    <div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong>Todo o cardápio está à venda</strong></div>
                <?php else: ?>
                    <ul class="lista">
                        <?php foreach ($analise['indisponiveis'] as $p): ?>
                            <li class="lista-item lista-item-simples">
                                <span class="lista-titulo">
                                    <strong><?= $e((string) $p['nome']) ?></strong>
                                    <small><?= $e((string) $p['motivoTexto']) ?></small>
                                </span>
                                <a class="btn-icone" href="<?= $e($base . '/estoque/produtos/' . (int) $p['id']) ?>"><?= Icon::svg('pencil', 'icon-sm') ?>Ver</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="bloco" aria-labelledby="titulo-acabando">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-acabando"><?= Icon::svg('clock') ?>Acabando em breve</h2>
                    <p>Com o estoque de agora, dá para vender só mais algumas unidades.</p>
                </div>
                <?php if ($analise['acabando'] === []): ?>
                    <div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong>Nenhum produto perto de acabar</strong></div>
                <?php else: ?>
                    <ul class="lista">
                        <?php foreach ($analise['acabando'] as $p): ?>
                            <li class="lista-item lista-item-simples">
                                <span class="lista-titulo">
                                    <strong><?= $e((string) $p['nome']) ?></strong>
                                    <small><?= $e((string) ($p['categoria'] ?? 'Sem categoria')) ?></small>
                                </span>
                                <span class="etiqueta etiqueta-atencao">Dá para vender mais <?= (int) $p['porcoes'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</div>
