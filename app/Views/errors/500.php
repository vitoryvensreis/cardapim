<?php use Cardapim\Core\Icon; ?>
<section class="error-stage" aria-labelledby="error-title">
    <div class="error-card" data-reveal>
        <span class="error-icon" aria-hidden="true"><?= Icon::svg('cooking-pot', 'icon-lg') ?></span>
        <p class="kicker">ALGO SAIU DO PONTO</p>
        <h1 id="error-title">Ops! Algo não saiu como esperado.</h1>
        <p>Nossa equipe já foi avisada pelos registros do sistema. Tente novamente em instantes.</p>
        <div class="error-actions">
            <a class="btn btn-wine" href="/"><?= Icon::svg('house') ?>Voltar ao início</a>
        </div>
    </div>
</section>
