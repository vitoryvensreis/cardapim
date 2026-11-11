<?php
/**
 * Convite para instalar o app (PWA), no rodape publico. Comeca escondido (e so aparece onde o pwa.js carrega:
 * login, cadastro e verificacao): o pwa.js mostra
 * so quando o aparelho permite instalar (botao) ou explica o caminho no iPhone/iPad. Ja instalado = some.
 */
use Cardapim\Core\AssetUrl;
use Cardapim\Core\Icon;
?>
<section class="pwa-instalar" aria-labelledby="pwa-titulo" data-pwa-instalar hidden>
    <img class="pwa-icone" src="<?= AssetUrl::asset('img/pwa/icon-192.png') ?>" width="48" height="48" alt="">
    <div class="pwa-texto">
        <h2 id="pwa-titulo">Instale o <?= marca_html() ?> no celular</h2>
        <p data-pwa-texto>Abra direto da tela inicial, em tela cheia, como um aplicativo.</p>
        <ol class="pwa-passos" data-pwa-ios hidden>
            <li>Toque em <?= Icon::svg('share', 'icon-sm') ?><strong>Compartilhar</strong> na barra do Safari.</li>
            <li>Escolha <?= Icon::svg('square-plus', 'icon-sm') ?><strong>Adicionar à Tela de Início</strong>.</li>
        </ol>
        <ol class="pwa-passos" data-pwa-menu hidden>
            <li>Abra o menu <?= Icon::svg('ellipsis-vertical', 'icon-sm') ?> do navegador.</li>
            <li>Toque em <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>.</li>
        </ol>
    </div>
    <button class="btn btn-wine btn-sm pwa-botao" type="button" data-pwa-botao hidden><?= Icon::svg('download', 'icon-sm') ?>Instalar app</button>
</section>
