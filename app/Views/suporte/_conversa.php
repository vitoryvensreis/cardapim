<?php
/**
 * Conversa do chamado. Espera: $mensagens, $lado (restaurante|suporte = quem esta vendo).
 */
use Cardapim\Core\Icon;

$escConv = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<ol class="suporte-conversa" aria-label="Mensagens do chamado">
    <?php foreach ($mensagens as $m):
        $meu = $m['origem'] === $lado;
        $quem = $m['origem'] === 'suporte' ? 'Suporte ' . marca() : (trim((string) ($m['autor'] ?? '')) ?: 'Restaurante'); ?>
        <li class="suporte-msg<?= $meu ? ' is-minha' : '' ?><?= $m['origem'] === 'suporte' ? ' is-suporte' : '' ?>">
            <p class="suporte-msg-autor"><?= Icon::svg($m['origem'] === 'suporte' ? 'life-buoy' : 'store', 'icon-sm') ?><strong><?= $escConv($quem) ?></strong>
                <?php if ($m['origem'] === 'suporte' && $lado === 'suporte' && !empty($m['autor'])): ?><span class="texto-suave">(<?= $escConv((string) $m['autor']) ?>)</span><?php endif; ?>
                <time class="texto-suave" datetime="<?= $escConv(date('c', (int) strtotime((string) $m['criado_em']))) ?>"><?= $escConv(date('d/m/Y H:i', (int) strtotime((string) $m['criado_em']))) ?></time></p>
            <div class="suporte-msg-texto"><?= nl2br($escConv((string) $m['texto'])) ?></div>
        </li>
    <?php endforeach; ?>
</ol>
