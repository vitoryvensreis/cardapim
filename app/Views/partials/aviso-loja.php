<?php
/**
 * Pop-up do aviso da plataforma para o gerente (AvisosLojaService::paraExibir). Aberto pelo aviso-loja.js.
 * Espera: $avisoLoja, $csrf, $e.
 */
use Cardapim\Core\Icon;

?>
<dialog class="aviso-loja" aria-labelledby="aviso-loja-titulo" data-aviso-loja data-clique="<?= $e((string) $avisoLoja['clique']) ?>" data-csrf="<?= $e((string) $csrf) ?>">
    <div class="aviso-loja-caixa">
        <button class="aviso-loja-fechar" type="button" aria-label="Fechar aviso" data-aviso-fechar><?= Icon::svg('x') ?></button>
        <?php if ($avisoLoja['imagem'] !== null): ?>
            <img class="aviso-loja-imagem" src="<?= $e((string) $avisoLoja['imagem']) ?>" alt="" width="540" height="540">
        <?php endif; ?>
        <p class="aviso-loja-origem"><?= Icon::svg('bell-ring', 'icon-sm') ?>Aviso da equipe <?= marca_html() ?></p>
        <h2 class="aviso-loja-titulo" id="aviso-loja-titulo"><?= $e($avisoLoja['titulo']) ?></h2>
        <?php if ($avisoLoja['texto'] !== null): ?>
            <p class="aviso-loja-texto"><?= nl2br($e($avisoLoja['texto'])) ?></p>
        <?php endif; ?>
        <div class="aviso-loja-acoes">
            <?php if ($avisoLoja['botao_url'] !== null && $avisoLoja['botao_texto'] !== null): ?>
                <a class="btn btn-primary btn-lg btn-block" href="<?= $e($avisoLoja['botao_url']) ?>" data-aviso-acao<?= $avisoLoja['externo'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <?= $e($avisoLoja['botao_texto']) ?><?= Icon::svg($avisoLoja['externo'] ? 'external-link' : 'arrow-right', 'icon-sm') ?>
                </a>
            <?php endif; ?>
            <button class="btn btn-ghost btn-block" type="button" data-aviso-fechar>Agora não</button>
        </div>
    </div>
</dialog>
