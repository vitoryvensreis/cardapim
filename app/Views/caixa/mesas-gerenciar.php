<?php
/**
 * Cadastro das mesas: adicionar uma, criar varias de uma vez, editar (numero, nome, lugares, pausar) e excluir.
 * Espera: $mesas, $editando, $base, $csrf, $erros, $enviado.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $campo, string $padrao = ''): string => (string) ($enviado[$campo] ?? $padrao);
$proximo = 1;
foreach ($mesas as $m) {
    $proximo = max($proximo, (int) $m['numero'] + 1);
}
$editandoId = is_int($editando ?? null) ? $editando : null;
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Cadastro de mesas</h1>
            <p>As mesas do salão. Cada mesa abre a própria comanda no caixa.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= $e($base . '/mesas') ?>"><?= Icon::svg('armchair', 'icon-sm') ?>Ver mapa das mesas</a>
    </header>

    <div class="caixa-colunas">
        <section class="bloco" aria-labelledby="titulo-nova-mesa">
            <div class="bloco-cabecalho"><h2 id="titulo-nova-mesa"><?= Icon::svg('plus') ?>Adicionar uma mesa</h2></div>
            <form method="post" action="<?= $e($base . '/mesas') ?>" novalidate data-validate-form class="form-mesa">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="campos-linha">
                    <?= Campo::texto(['nome' => 'numero', 'id' => 'nova-numero', 'rotulo' => 'Número', 'valor' => $editandoId === null ? $v('numero', (string) $proximo) : (string) $proximo, 'icone' => 'hash',
                        'erro' => $editandoId === null ? ($erros['numero'] ?? null) : null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4]]) ?>
                    <?= Campo::texto(['nome' => 'lugares', 'id' => 'nova-lugares', 'rotulo' => 'Lugares', 'valor' => $editandoId === null ? $v('lugares') : '', 'icone' => 'users', 'opcional' => true,
                        'erro' => $editandoId === null ? ($erros['lugares'] ?? null) : null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 2, 'placeholder' => '4']]) ?>
                </div>
                <?= Campo::texto(['nome' => 'apelido', 'id' => 'nova-apelido', 'rotulo' => 'Nome ou lugar', 'valor' => $editandoId === null ? $v('apelido') : '', 'icone' => 'tag', 'opcional' => true,
                    'erro' => $editandoId === null ? ($erros['apelido'] ?? null) : null, 'dica' => 'Ex.: Varanda, Perto da janela.', 'attrs' => ['maxlength' => 40]]) ?>
                <button class="btn btn-primary btn-block" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Adicionar mesa</button>
            </form>
        </section>

        <section class="bloco" aria-labelledby="titulo-varias">
            <div class="bloco-cabecalho">
                <h2 id="titulo-varias"><?= Icon::svg('armchair') ?>Criar várias de uma vez</h2>
                <p>Números que já existem são pulados.</p>
            </div>
            <form method="post" action="<?= $e($base . '/mesas/varias') ?>" novalidate data-validate-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="campos-linha">
                    <?= Campo::texto(['nome' => 'de', 'id' => 'varias-de', 'rotulo' => 'Da mesa', 'valor' => (string) $proximo, 'icone' => 'hash', 'erro' => $erros['de'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4]]) ?>
                    <?= Campo::texto(['nome' => 'ate', 'id' => 'varias-ate', 'rotulo' => 'Até a mesa', 'valor' => (string) ($proximo + 9), 'icone' => 'hash', 'erro' => $erros['ate'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4]]) ?>
                </div>
                <button class="btn btn-outline btn-block" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Criar mesas</button>
            </form>
        </section>
    </div>

    <?php if ($mesas === []): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('armchair') ?><strong>Nenhuma mesa ainda</strong><p>Use os formulários acima para criar as mesas do salão.</p></div></section>
    <?php else: ?>
        <ul class="lista lista-mesas">
            <li class="lista-cabecalho" aria-hidden="true"><span>Mesa</span><span>Lugares</span><span>Situação</span><span class="lista-cab-acoes">Ações</span></li>
            <?php foreach ($mesas as $m):
                $ocupada = $m['comanda_id'] !== null;
                $pausada = (int) $m['ativo'] !== 1;
                $abertoEdicao = $editandoId === (int) $m['id']; ?>
                <li class="lista-item">
                    <span class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg('armchair', 'icon-sm') ?></span>
                        <span><strong>Mesa <?= (int) $m['numero'] ?></strong><?php if ($m['apelido']): ?><small><?= $e((string) $m['apelido']) ?></small><?php endif; ?></span>
                    </span>
                    <span class="lista-dado" data-rotulo="Lugares"><?= $m['lugares'] ? (int) $m['lugares'] : '<span class="texto-suave">—</span>' ?></span>
                    <span class="lista-dado" data-rotulo="Situação">
                        <?php if ($ocupada): ?><span class="etiqueta etiqueta-marca"><?= Icon::svg('utensils') ?>Ocupada</span>
                        <?php elseif ($pausada): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('pause') ?>Pausada</span>
                        <?php else: ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Livre</span><?php endif; ?>
                    </span>
                    <span class="lista-acoes">
                        <form method="post" action="<?= $e($base . '/mesas/' . (int) $m['id'] . '/excluir') ?>"
                              data-confirm="<?= (int) $m['historico'] > 0 ? 'Os pedidos antigos desta mesa continuam no histórico.' : 'A mesa sai do mapa.' ?>" data-confirm-titulo="Excluir a mesa <?= (int) $m['numero'] ?>?" data-confirm-acao="Excluir" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-ghost btn-sm area-remover" type="submit"<?= $ocupada ? ' disabled title="Feche a comanda antes de excluir"' : '' ?>><?= Icon::svg('trash', 'icon-sm') ?>Excluir</button>
                        </form>
                    </span>
                    <details class="mesa-editar"<?= $abertoEdicao ? ' open' : '' ?>>
                        <summary><?= Icon::svg('pencil', 'icon-sm') ?>Editar</summary>
                        <form method="post" action="<?= $e($base . '/mesas/' . (int) $m['id']) ?>" novalidate data-validate-form>
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <div class="campos-linha">
                                <?= Campo::texto(['nome' => 'numero', 'id' => 'mesa' . (int) $m['id'] . '-numero', 'rotulo' => 'Número', 'valor' => $abertoEdicao ? $v('numero') : (string) $m['numero'], 'icone' => 'hash',
                                    'erro' => $abertoEdicao ? ($erros['numero'] ?? null) : null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4]]) ?>
                                <?= Campo::texto(['nome' => 'lugares', 'id' => 'mesa' . (int) $m['id'] . '-lugares', 'rotulo' => 'Lugares', 'valor' => $abertoEdicao ? $v('lugares') : (string) ($m['lugares'] ?? ''), 'icone' => 'users', 'opcional' => true,
                                    'erro' => $abertoEdicao ? ($erros['lugares'] ?? null) : null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 2]]) ?>
                            </div>
                            <?= Campo::texto(['nome' => 'apelido', 'id' => 'mesa' . (int) $m['id'] . '-apelido', 'rotulo' => 'Nome ou lugar', 'valor' => $abertoEdicao ? $v('apelido') : (string) ($m['apelido'] ?? ''), 'icone' => 'tag', 'opcional' => true,
                                'erro' => $abertoEdicao ? ($erros['apelido'] ?? null) : null, 'attrs' => ['maxlength' => 40]]) ?>
                            <?= Campo::alternador('ativo', 'Mesa em uso', 'Desligue para tirar do mapa por um tempo (ex.: mesa quebrada).', !$pausada) ?>
                            <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar</button>
                        </form>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
