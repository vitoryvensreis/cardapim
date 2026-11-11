<?php
/** Painel lateral das telas de acesso. Espera $asideTitle e $asideItems. */
use Cardapim\Core\Icon;
?>
<aside class="auth-aside" aria-label="Por que usar o Cardapim">
    <h2><?= htmlspecialchars($asideTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
    <ul class="auth-benefits">
        <?php foreach ($asideItems as [$icon, $title, $text]): ?>
            <li data-reveal>
                <span class="auth-benefit-icon"><?= Icon::svg($icon) ?></span>
                <span><strong><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></strong><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>
