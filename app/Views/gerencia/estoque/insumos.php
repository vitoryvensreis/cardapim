<?php
/** Lista de insumos. Espera: $insumos (com 'analise'), $filtro, $base, $csrf, $contagem, $abaAtual. */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Modules\Inventory\UnidadeMedida;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filtros = ['todos' => 'Todos', 'atencao' => 'Acabando ou em falta', 'ok' => 'Em dia'];
$etiquetas = [
    AnaliseEstoque::FALTA => ['etiqueta-alerta', 'package-x', 'Em falta'],
    AnaliseEstoque::BAIXO => ['etiqueta-atencao', 'triangle-exclamation-point', 'Baixo'],
    AnaliseEstoque::OK => ['etiqueta-ok', 'check', 'Em dia'],
    AnaliseEstoque::INATIVO => ['etiqueta-neutra', 'power', 'Desativado'],
];
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Estoque</h1>
            <p>Ingredientes, bebidas e tudo que você compra.</p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-primary btn-sm" href="<?= $e($base . '/estoque/insumos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo insumo</a>
        </div>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>

    <?php if ($contagem['insumos'] === 0): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('wheat') ?>
                <strong>Nenhum insumo cadastrado</strong>
                <p>Cadastre o que você compra (pão, carne, refrigerante) e quanto tem de cada um. O <?= marca_html() ?> avisa quando estiver acabando.</p>
                <p class="lista-vazia-acao"><a class="btn btn-primary" href="<?= $e($base . '/estoque/insumos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar insumo</a></p>
            </div>
        </section>
    <?php else: ?>
        <div class="ferramentas">
            <label class="busca">
                <?= Icon::svg('search', 'icon-sm') ?>
                <span class="sr-only">Buscar insumo</span>
                <input type="search" placeholder="Buscar pelo nome" data-filtro="#lista-insumos" data-filtro-vazio="#insumos-nenhum" autocomplete="off">
            </label>
            <nav class="filtros" aria-label="Filtrar insumos">
                <?php foreach ($filtros as $chave => $rotulo): ?>
                    <a href="<?= $e($base . '/estoque/insumos' . ($chave === 'todos' ? '' : '?filtro=' . $chave)) ?>"<?= $filtro === $chave ? ' aria-current="true"' : '' ?>><?= $e($rotulo) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ($insumos === []): ?>
            <div class="bloco"><div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong>Nenhum insumo neste filtro</strong><p><a href="<?= $e($base . '/estoque/insumos') ?>">Ver todos os insumos</a></p></div></div>
        <?php else: ?>
            <ul class="lista lista-insumos" id="lista-insumos">
                <li class="lista-cabecalho" aria-hidden="true"><span>Insumo</span><span>Quanto tem</span><span>Mínimo</span><span>Custo</span><span class="lista-cab-acoes">Ações</span></li>
                <?php foreach ($insumos as $i):
                    $id = (int) $i['id'];
                    $a = $i['analise'];
                    $u = UnidadeMedida::from((string) $i['unidade']);
                    [$classe, $icone, $rotulo] = $etiquetas[$a['situacao']];
                ?>
                    <li class="lista-item" data-filtro-item="<?= $e((string) $i['nome']) ?>">
                        <div class="lista-principal">
                            <span class="lista-icone" aria-hidden="true"><?= $e($u->sigla()) ?></span>
                            <span class="lista-titulo">
                                <a href="<?= $e($base . '/estoque/insumos/' . $id) ?>"><strong><?= $e((string) $i['nome']) ?></strong></a>
                                <small><?= (int) $i['usos'] === 0 ? 'Não está em nenhum produto' : 'Usado em ' . (int) $i['usos'] . ' produto(s)' ?></small>
                            </span>
                        </div>
                        <span class="lista-dado" data-rotulo="Quanto tem">
                            <span class="nivel">
                                <span class="etiqueta <?= $classe ?>"><?= Icon::svg($icone) ?><?= $e($rotulo) ?>: <?= $e($u->formatar($a['atual'])) ?></span>
                                <span class="nivel-barra" aria-hidden="true"><span class="n-<?= (int) $a['nivel'] ?>"></span></span>
                            </span>
                        </span>
                        <span class="lista-dado" data-rotulo="Mínimo"><?= $a['minimo'] > 0 ? $e($u->formatar($a['minimo'])) : '<span class="texto-suave">Não definido</span>' ?></span>
                        <span class="lista-dado" data-rotulo="Custo"><?= $i['custo_unitario'] !== null ? $e('R$ ' . Numero::decimalCampo((string) $i['custo_unitario']) . ' / ' . $u->sigla()) : '<span class="texto-suave">Não informado</span>' ?></span>
                        <div class="lista-acoes">
                            <a class="btn-icone" href="<?= $e($base . '/estoque/insumos/' . $id) ?>"><?= Icon::svg('pencil', 'icon-sm') ?><span>Editar</span></a>
                            <?php if ((int) $i['usos'] === 0): ?>
                                <form method="post" action="<?= $e($base . '/estoque/insumos/' . $id . '/excluir') ?>"
                                      data-confirm="O insumo será apagado. Isso não pode ser desfeito."
                                      data-confirm-titulo="Excluir &quot;<?= $e((string) $i['nome']) ?>&quot;?" data-confirm-acao="Excluir insumo" data-confirm-perigo="1">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button class="btn-icone is-perigo" type="submit" aria-label="Excluir <?= $e((string) $i['nome']) ?>"><?= Icon::svg('trash', 'icon-sm') ?></button>
                                </form>
                            <?php else: ?>
                                <span class="btn-icone is-bloqueado" title="Está na ficha de algum produto. Tire das fichas para poder excluir." aria-label="Não pode excluir: está em uso"><?= Icon::svg('lock', 'icon-sm') ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="lista-vazia" id="insumos-nenhum" hidden>Nenhum insumo encontrado com essa busca.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
