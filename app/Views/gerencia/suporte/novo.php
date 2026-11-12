<?php
/** Abrir chamado. Espera: $valores, $erros, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Services\Suporte\SuporteService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/suporte') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Suporte</a>
            <h1>Abrir chamado</h1>
            <p>Conte o que aconteceu e, se for um problema, o que você estava fazendo na hora. Quanto mais detalhes, mais rápida a ajuda.</p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($base . '/suporte') ?>" novalidate data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <section class="bloco" aria-labelledby="titulo-chamado">
            <div class="bloco-cabecalho"><h2 id="titulo-chamado"><?= Icon::svg('life-buoy') ?>Seu chamado</h2></div>
            <?= Campo::selecao(['nome' => 'categoria', 'rotulo' => 'Sobre o que é?', 'icone' => 'tags', 'valor' => $valores['categoria'], 'vazio' => 'Escolha o assunto',
                'erro' => $erros['categoria'] ?? null, 'attrs' => ['required' => true]], SuporteService::CATEGORIAS) ?>
            <?= Campo::texto(['nome' => 'assunto', 'rotulo' => 'Assunto', 'valor' => $valores['assunto'], 'icone' => 'pencil', 'erro' => $erros['assunto'] ?? null,
                'dica' => 'Em poucas palavras. Exemplo: Pedido do iFood não apareceu no caixa', 'attrs' => ['maxlength' => 120, 'data-validate' => 'required', 'autocomplete' => 'off']]) ?>
            <?= Campo::areaTexto(['nome' => 'mensagem', 'rotulo' => 'O que aconteceu?', 'valor' => $valores['mensagem'], 'icone' => 'message-circle', 'erro' => $erros['mensagem'] ?? null,
                'dica' => 'Exemplo: às 19h o pedido #42 do iFood não apareceu; o som tocou, mas a tela ficou vazia.', 'attrs' => ['rows' => 7, 'maxlength' => 4000, 'required' => true]]) ?>
            <div class="bloco-rodape">
                <a class="btn btn-outline" href="<?= $e($base . '/suporte') ?>">Cancelar</a>
                <button class="btn btn-primary" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?>Enviar chamado</button>
            </div>
        </section>
    </form>
</div>
