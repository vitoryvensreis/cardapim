<?php
/** Secao do painel ainda nao construida. Espera: $secao (titulo, descricao, icone, planejado), $usuario. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page">
    <header class="painel-page-head" data-reveal>
        <span class="painel-page-icon"><?= Icon::svg($secao['icone'], 'icon-lg') ?></span>
        <div>
            <?php if (!empty($saudacao)): ?><p class="painel-hello">Olá, <?= $e($usuario->nome) ?>!</p><?php endif; ?>
            <h1><?= $e($secao['titulo']) ?></h1>
            <p><?= $e($secao['descricao']) ?></p>
        </div>
    </header>

    <section class="painel-construcao" data-reveal aria-labelledby="construcao-titulo">
        <span class="painel-construcao-icon" aria-hidden="true"><?= Icon::svg('cooking-pot', 'icon-lg') ?></span>
        <h2 id="construcao-titulo">Em construção</h2>
        <p>Esta parte do painel está sendo preparada. Veja o que vai aparecer aqui:</p>
        <ul class="painel-planejado">
            <?php foreach ($secao['planejado'] as $item): ?>
                <li><?= Icon::svg('check', 'icon-sm') ?><?= $e($item) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
