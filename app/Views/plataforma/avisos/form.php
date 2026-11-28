<?php
/** Criar/editar aviso para as lojas. Espera: $aviso (null = novo), $valores, $erros, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\AvisosLojaService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $c): string => is_scalar($valores[$c] ?? null) ? (string) $valores[$c] : '';
$novo = $aviso === null;
$imagemAtual = $aviso['imagem_path'] ?? null;
$destinos = ['' => 'Sem botão'] + array_map(static fn (array $d): string => $d[0], AvisosLojaService::DESTINOS) + [AvisosLojaService::EXTERNO => 'Outro endereço (link externo)'];
$vezes = [];
for ($i = 1; $i <= AvisosLojaService::VEZES_MAX; $i++) {
    $vezes[(string) $i] = $i === 1 ? '1 vez por dia' : $i . ' vezes por dia';
}
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/avisos') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Avisos para as lojas</a>
            <h1><?= $novo ? 'Novo aviso' : $e((string) $aviso['titulo']) ?></h1>
            <p>Aparece como pop-up para o gerente de todas as lojas, em qualquer tela da gerência.</p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <div class="aviso-form-grade">
        <form method="post" action="<?= $e($base . '/avisos' . ($novo ? '' : '/' . (int) $aviso['id'])) ?>" enctype="multipart/form-data" novalidate data-validate-form data-aviso-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

            <section class="bloco" aria-labelledby="titulo-conteudo">
                <div class="bloco-cabecalho"><h2 id="titulo-conteudo"><?= Icon::svg('message-circle') ?>O que o gerente vê</h2></div>
                <?= Campo::texto(['nome' => 'titulo', 'rotulo' => 'Título', 'valor' => $v('titulo'), 'icone' => 'type', 'erro' => $erros['titulo'] ?? null,
                    'dica' => 'Curto e direto. Ex.: Reconecte o WhatsApp da sua loja',
                    'attrs' => ['maxlength' => 80, 'autocomplete' => 'off', 'data-aviso-titulo' => true]]) ?>
                <?= Campo::areaTexto(['nome' => 'texto', 'rotulo' => 'Mensagem', 'valor' => $v('texto'), 'opcional' => true, 'erro' => $erros['texto'] ?? null,
                    'dica' => 'Até 600 letras. Ex.: Trocamos o servidor do WhatsApp. Toque no botão e leia o QR Code com o celular da loja (leva 1 minuto).',
                    'attrs' => ['maxlength' => 600, 'rows' => 4, 'data-aviso-texto' => true]]) ?>

                <div id="campo-imagem">
                    <label class="popup-envio<?= isset($erros['imagem']) ? ' is-invalido' : '' ?>">
                        <?= Icon::svg('upload') ?>
                        <span><strong><?= $imagemAtual !== null ? 'Trocar a imagem' : 'Imagem (opcional)' ?></strong>
                            <small>Quadrada (1:1), ideal 1080 x 1080. PNG, JPG ou WebP até 5 MB. Se não for quadrada, o centro é recortado.</small></span>
                        <input type="file" name="imagem" accept="image/png,image/jpeg,image/webp" data-aviso-arquivo<?= isset($erros['imagem']) ? ' aria-invalid="true"' : '' ?>>
                    </label>
                    <?php if (isset($erros['imagem'])): ?><p class="campo-erro" role="alert"><?= $e($erros['imagem']) ?></p><?php endif; ?>
                    <?php if ($imagemAtual !== null): ?>
                        <label class="aviso-remover-imagem"><input type="checkbox" name="remover_imagem" value="1" data-aviso-remover> Tirar a imagem atual</label>
                    <?php endif; ?>
                </div>
            </section>

            <section class="bloco" aria-labelledby="titulo-botao">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-botao"><?= Icon::svg('link') ?>Botão de ação</h2>
                    <p>Escolha uma tela da loja: o link muda sozinho para cada loja.</p>
                </div>
                <div class="form-grade">
                    <?= Campo::selecao(['nome' => 'botao_destino', 'rotulo' => 'O botão leva para', 'valor' => $v('botao_destino'), 'icone' => 'link', 'opcional' => true,
                        'erro' => $erros['botao_destino'] ?? null, 'attrs' => ['data-aviso-destino' => true]], $destinos) ?>
                    <div data-aviso-com-botao>
                        <?= Campo::texto(['nome' => 'botao_texto', 'rotulo' => 'Texto do botão', 'valor' => $v('botao_texto'), 'icone' => 'type', 'opcional' => true,
                            'erro' => $erros['botao_texto'] ?? null, 'dica' => 'Ex.: Reconectar agora',
                            'attrs' => ['maxlength' => 40, 'autocomplete' => 'off', 'data-aviso-botao' => true]]) ?>
                    </div>
                    <div class="col-inteira" data-aviso-externo>
                        <?= Campo::texto(['nome' => 'botao_url', 'rotulo' => 'Endereço do link', 'valor' => $v('botao_url'), 'icone' => 'external-link', 'opcional' => true,
                            'erro' => $erros['botao_url'] ?? null, 'dica' => 'Completo, começando com https://. Abre em outra aba.',
                            'attrs' => ['maxlength' => 500, 'inputmode' => 'url', 'autocomplete' => 'off', 'placeholder' => 'https://']]) ?>
                    </div>
                </div>
            </section>

            <section class="bloco" aria-labelledby="titulo-quando">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-quando"><?= Icon::svg('calendar-range') ?>Quando aparece</h2>
                    <p>Para mostrar só hoje uma vez: início agora, fim hoje às 23:59 e 1 vez por dia.</p>
                </div>
                <div class="form-grade">
                    <?= Campo::texto(['nome' => 'inicio', 'rotulo' => 'Começa em', 'tipo' => 'datetime-local', 'valor' => $v('inicio'), 'icone' => 'calendar', 'erro' => $erros['inicio'] ?? null]) ?>
                    <?= Campo::texto(['nome' => 'fim', 'rotulo' => 'Termina em', 'tipo' => 'datetime-local', 'valor' => $v('fim'), 'icone' => 'calendar', 'erro' => $erros['fim'] ?? null]) ?>
                    <?= Campo::selecao(['nome' => 'vezes_por_dia', 'rotulo' => 'Quantas vezes cada gerente vê por dia', 'valor' => $v('vezes_por_dia') ?: '1', 'icone' => 'repeat',
                        'erro' => $erros['vezes_por_dia'] ?? null, 'classe' => 'col-inteira',
                        'dica' => 'Conta cada vez que o gerente abre uma tela da gerência com o aviso.'], $vezes) ?>
                </div>
            </section>

            <div class="form-acoes">
                <a class="btn btn-outline" href="<?= $e($base . '/avisos') ?>">Cancelar</a>
                <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('check', 'icon-sm') ?><?= $novo ? 'Criar aviso' : 'Salvar' ?></button>
            </div>
        </form>

        <aside class="aviso-previa-area" aria-label="Prévia do aviso">
            <p class="aviso-previa-rotulo"><?= Icon::svg('eye', 'icon-sm') ?>Prévia</p>
            <div class="aviso-loja-caixa aviso-previa">
                <img class="aviso-loja-imagem" alt="" data-previa-imagem<?= $imagemAtual !== null ? ' src="' . $e((string) $imagemAtual) . '"' : ' hidden' ?>>
                <h2 class="aviso-loja-titulo" data-previa-titulo><?= $e($v('titulo') ?: 'Título do aviso') ?></h2>
                <p class="aviso-loja-texto" data-previa-texto<?= $v('texto') === '' ? ' hidden' : '' ?>><?= $e($v('texto')) ?></p>
                <div class="aviso-loja-acoes">
                    <span class="btn btn-primary btn-block" data-previa-botao<?= $v('botao_destino') === '' ? ' hidden' : '' ?>><?= $e($v('botao_texto') ?: 'Texto do botão') ?></span>
                    <span class="btn btn-ghost btn-block">Agora não</span>
                </div>
            </div>
        </aside>
    </div>
</div>
