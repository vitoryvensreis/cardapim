<?php use Cardapim\Core\Icon; ?>
<section class="error-stage" aria-labelledby="not-found-title">
    <div class="error-card" data-reveal>
        <span class="error-number" aria-hidden="true">404</span>
        <h1 id="not-found-title">Esse caminho não existe.</h1>
        <p>Talvez a página tenha mudado de endereço ou o link esteja incorreto. Você pode voltar ao início e continuar navegando.</p>
        <div class="error-actions">
            <a class="btn btn-wine" href="/"><?= Icon::svg('house') ?>Voltar ao início</a>
            <a class="btn btn-outline" href="/#planos"><?= Icon::svg('wallet') ?>Conhecer os planos</a>
        </div>
    </div>
</section>
