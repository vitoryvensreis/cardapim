<?php
/**
 * Clientes > Popup do cardapio: banner quadrado (1:1) ou texto que abre quando o cliente entra no link de pedidos.
 * Previa ao vivo no popup-gerencia.js (imagem escolhida aparece antes de salvar).
 * Espera: $valores, $erros, $config, $disponivel, $visivelHoje, $imagemAtual, $linkCardapio, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\PopupService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $k): string => (string) ($valores[$k] ?? '');
$tipo = $v('tipo') === 'texto' ? 'texto' : 'imagem';
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1>Popup do cardápio</h1>
            <p>Uma imagem ou um aviso que abre quando o cliente entra no seu link de pedidos. Bom para promoções, novidades e avisos de horário.</p>
        </div>
        <?php if ($visivelHoje): ?>
            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Aparecendo agora</span>
        <?php elseif ($config['ativo']): ?>
            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('calendar-clock') ?>Fora do período</span>
        <?php else: ?>
            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('circle-pause') ?>Desligado</span>
        <?php endif; ?>
    </header>

    <?php if (!$disponivel): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('clock') ?><strong>Em ativação</strong><p>O popup fica disponível em alguns minutos.</p></div></section>
    <?php else: ?>
    <?php if (isset($erros['geral'])): ?><div class="notice notice-error" role="alert"><?= $e($erros['geral']) ?></div><?php endif; ?>
    <?= Campo::resumoErros(array_diff_key($erros, ['geral' => 1])) ?>

    <div class="popup-gerencia" data-popup-gerencia>
        <form method="post" action="<?= $e($base . '/popup') ?>" enctype="multipart/form-data" class="popup-gerencia-form" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <section class="bloco" aria-labelledby="titulo-ligar">
                <div class="bloco-cabecalho"><h2 id="titulo-ligar"><?= Icon::svg('sparkles') ?>Mostrar o popup</h2></div>
                <?= Campo::alternador('ativo', 'Popup ligado', 'Ligado: abre uma vez por visita quando o cliente entra no cardápio. Quando você muda o popup, ele aparece de novo para todos.', $v('ativo') === '1') ?>
            </section>

            <section class="bloco" aria-labelledby="titulo-tipo">
                <div class="bloco-cabecalho"><h2 id="titulo-tipo"><?= Icon::svg('layout-grid') ?>O que mostrar</h2></div>
                <fieldset class="opcoes-modo popup-tipos">
                    <legend class="sr-only">Tipo do popup</legend>
                    <label class="opcao-modo">
                        <input type="radio" name="tipo" value="imagem" data-popup-tipo<?= $tipo === 'imagem' ? ' checked' : '' ?>>
                        <span><strong><?= Icon::svg('image', 'icon-sm') ?>Imagem</strong><small>Um banner quadrado, como um post do Instagram.</small></span>
                    </label>
                    <label class="opcao-modo">
                        <input type="radio" name="tipo" value="texto" data-popup-tipo<?= $tipo === 'texto' ? ' checked' : '' ?>>
                        <span><strong><?= Icon::svg('type', 'icon-sm') ?>Texto</strong><small>Um título e uma mensagem curta.</small></span>
                    </label>
                </fieldset>

                <div class="popup-campo-imagem" data-popup-parte="imagem"<?= $tipo === 'imagem' ? '' : ' hidden' ?> id="campo-imagem">
                    <label class="popup-envio<?= isset($erros['imagem']) ? ' is-invalido' : '' ?>">
                        <?= Icon::svg('upload') ?>
                        <span><strong><?= $imagemAtual !== null ? 'Trocar a imagem' : 'Escolher a imagem' ?></strong>
                            <small>Quadrada (1:1), ideal 1080 x 1080. PNG, JPG ou WebP até 5 MB. Se não for quadrada, o centro é recortado.</small></span>
                        <input type="file" name="imagem" accept="image/png,image/jpeg,image/webp" data-popup-arquivo<?= isset($erros['imagem']) ? ' aria-invalid="true"' : '' ?>>
                    </label>
                    <p class="popup-arquivo-nome" data-popup-arquivo-nome hidden></p>
                    <?php if (isset($erros['imagem'])): ?><p class="campo-erro"><?= $e($erros['imagem']) ?></p><?php endif; ?>
                </div>

                <div class="popup-campo-texto" data-popup-parte="texto"<?= $tipo === 'texto' ? '' : ' hidden' ?>>
                    <?= Campo::texto(['nome' => 'titulo', 'rotulo' => 'Título', 'valor' => $v('titulo'), 'icone' => 'type', 'opcional' => true,
                        'erro' => $erros['titulo'] ?? null, 'dica' => 'Ex.: Hoje tem pizza em dobro!',
                        'attrs' => ['maxlength' => PopupService::MAX_TITULO, 'data-popup-titulo' => true]]) ?>
                    <?= Campo::areaTexto(['nome' => 'texto', 'rotulo' => 'Mensagem', 'valor' => $v('texto'), 'opcional' => true,
                        'erro' => $erros['texto'] ?? null, 'dica' => 'Até ' . PopupService::MAX_TEXTO . ' letras. Ex.: Na compra de uma pizza grande, a segunda sai pela metade do preço.',
                        'attrs' => ['maxlength' => PopupService::MAX_TEXTO, 'rows' => 4, 'data-popup-texto' => true]]) ?>
                </div>
            </section>

            <section class="bloco" aria-labelledby="titulo-periodo">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-periodo"><?= Icon::svg('calendar-range') ?>Período <small>(opcional)</small></h2>
                    <p>Deixe em branco para mostrar até você desligar. As datas valem o dia inteiro.</p>
                </div>
                <div class="form-grade">
                    <?= Campo::texto(['nome' => 'inicio', 'rotulo' => 'Começa em', 'tipo' => 'date', 'valor' => $v('inicio'), 'icone' => 'calendar', 'opcional' => true, 'erro' => $erros['inicio'] ?? null]) ?>
                    <?= Campo::texto(['nome' => 'fim', 'rotulo' => 'Termina em', 'tipo' => 'date', 'valor' => $v('fim'), 'icone' => 'calendar', 'opcional' => true, 'erro' => $erros['fim'] ?? null]) ?>
                </div>
            </section>

            <div class="bloco-rodape popup-acoes">
                <button class="btn btn-primary" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar popup</button>
                <a class="btn btn-outline" href="<?= $e($linkCardapio) ?>" target="_blank" rel="noopener"><?= Icon::svg('external-link', 'icon-sm') ?>Ver no cardápio</a>
            </div>
        </form>

        <aside class="popup-previa" aria-labelledby="titulo-previa">
            <h2 id="titulo-previa"><?= Icon::svg('eye', 'icon-sm') ?>Como o cliente vê</h2>
            <div class="popup-previa-tela">
                <div class="popup-previa-caixa">
                    <span class="popup-previa-fechar" aria-hidden="true"><?= Icon::svg('x', 'icon-sm') ?></span>
                    <div data-previa-parte="imagem"<?= $tipo === 'imagem' ? '' : ' hidden' ?>>
                        <img class="popup-previa-imagem" src="<?= $e((string) ($imagemAtual ?? '')) ?>" alt="Prévia da imagem do popup" data-previa-imagem<?= $imagemAtual === null ? ' hidden' : '' ?>>
                        <div class="popup-previa-vazia" data-previa-vazia<?= $imagemAtual !== null ? ' hidden' : '' ?>><?= Icon::svg('image', 'icon-lg') ?><span>Sua imagem quadrada aparece aqui</span></div>
                    </div>
                    <div class="popup-previa-texto" data-previa-parte="texto"<?= $tipo === 'texto' ? '' : ' hidden' ?>>
                        <strong data-previa-titulo><?= $e($v('titulo') !== '' ? $v('titulo') : 'Título do popup') ?></strong>
                        <p data-previa-texto><?= $e($v('texto') !== '' ? $v('texto') : 'Sua mensagem aparece aqui.') ?></p>
                    </div>
                    <span class="popup-previa-botao">Ver cardápio</span>
                </div>
            </div>
            <?php if ($imagemAtual !== null): ?>
                <form method="post" action="<?= $e($base . '/popup/imagem/remover') ?>" class="popup-remover" data-confirm="A imagem sai do popup. Se o popup for de imagem, ele é desligado." data-confirm-titulo="Remover a imagem?" data-confirm-acao="Remover imagem" data-confirm-perigo="1">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-ghost btn-sm area-remover" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Remover imagem</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
    <?php endif; ?>
</div>
