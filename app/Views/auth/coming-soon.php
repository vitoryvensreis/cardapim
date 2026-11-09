<section class="coming-soon">
    <div class="shell">
        <div class="coming-card">
            <span class="eyebrow eyebrow-light"><span class="signal-dot"></span> Estamos construindo</span>
            <h1><?= htmlspecialchars($flowTitle ?? 'Em breve.', ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($flowDescription ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            <a class="button button-lime" href="/">Voltar para o início <span aria-hidden="true">↗</span></a>
        </div>
    </div>
</section>
