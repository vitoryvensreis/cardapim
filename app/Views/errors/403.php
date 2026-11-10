<?php use Cardapim\Core\Icon; ?>
<section class="error-stage" aria-labelledby="forbidden-title">
    <div class="error-card" data-reveal>
        <span class="error-icon" aria-hidden="true"><?= Icon::svg('lock', 'icon-lg') ?></span>
        <p class="kicker">ACESSO NÃO PERMITIDO</p>
        <h1 id="forbidden-title">Essa área não é para o seu perfil.</h1>
        <p><?= htmlspecialchars($motivo !== '' ? $motivo : 'Você não tem permissão para acessar este endereço.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <div class="error-actions">
            <a class="btn btn-wine" href="<?= htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') ?>"><?= Icon::svg('house') ?>Ir para a minha área</a>
        </div>
    </div>
</section>
