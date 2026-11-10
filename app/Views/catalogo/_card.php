<?php
/** Card de restaurante. Espera $r (nome, url, logo, iniciais). Mesma estrutura do <template> usado pelo JS. */
use Cardapim\Core\Icon;

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<li class="rest-card">
    <a class="rest-link" href="<?= $esc($r['url']) ?>">
        <span class="rest-avatar">
            <?php if ($r['logo'] !== null): ?>
                <img src="<?= $esc($r['logo']) ?>" alt="" width="64" height="64" loading="lazy">
            <?php else: ?>
                <span class="rest-initials" aria-hidden="true"><?= $esc($r['iniciais']) ?></span>
            <?php endif; ?>
        </span>
        <span class="rest-info">
            <strong class="rest-name"><?= $esc($r['nome']) ?></strong>
            <span class="rest-meta"><?= Icon::svg('utensils-crossed', 'icon-sm') ?>Cardápio em breve</span>
        </span>
        <span class="rest-go" aria-hidden="true"><?= Icon::svg('arrow-up-right') ?></span>
    </a>
</li>
