<?php
/**
 * Mesas do garcom: livre (toque para abrir a conta) ou ocupada (total e tempo). Atualiza sozinha (tempo real).
 * Espera: $mesas (ativas, com a comanda aberta se houver), $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tempo = static function (string $desde): string {
    $m = max(0, (int) floor((time() - (int) strtotime($desde)) / 60));
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
$ocupadas = count(array_filter($mesas, static fn (array $m): bool => $m['comanda_id'] !== null));
$livres = count($mesas) - $ocupadas;
?>
<div class="garcom-pagina" data-regiao="mesas">
    <header class="garcom-cabecalho">
        <h1>Mesas</h1>
        <?php if ($mesas !== []): ?>
            <nav class="garcom-filtros" aria-label="Filtrar mesas" data-mesas-filtro>
                <button type="button" aria-pressed="true" data-filtro-mesa="todas"><span>Todas</span><b><?= count($mesas) ?></b></button>
                <button type="button" aria-pressed="false" data-filtro-mesa="livre" class="is-livre"><?= Icon::svg('circle-check', 'icon-sm') ?><span>Livres</span><b><?= $livres ?></b></button>
                <button type="button" aria-pressed="false" data-filtro-mesa="ocupada" class="is-ocupada"><?= Icon::svg('utensils', 'icon-sm') ?><span>Ocupadas</span><b><?= $ocupadas ?></b></button>
            </nav>
        <?php endif; ?>
    </header>

    <?php if ($mesas === []): ?>
        <div class="garcom-vazio">
            <span class="garcom-vazio-icone" aria-hidden="true"><?= Icon::svg('armchair', 'icon-lg') ?></span>
            <strong>Nenhuma mesa ainda</strong>
            <p>Peça ao gerente ou ao caixa para criar as mesas do salão.</p>
        </div>
    <?php else: ?>
        <ul class="mesas-grade garcom-mesas" data-mesas>
            <?php foreach ($mesas as $m): $ocupada = $m['comanda_id'] !== null; $lugares = $m['lugares'] !== null ? (int) $m['lugares'] : null; ?>
                <li data-mesa-estado="<?= $ocupada ? 'ocupada' : 'livre' ?>">
                    <?php if ($ocupada): ?>
                        <a class="mesa is-ocupada" href="<?= $e($base . '/comandas/' . (int) $m['comanda_id']) ?>" aria-label="Mesa <?= (int) $m['numero'] ?>, <?= (int) $m['comandas'] > 1 ? (int) $m['comandas'] . ' comandas' : 'ocupada' ?>, <?= $e(Numero::reais((string) $m['comanda_total'])) ?>">
                            <?php $centro = '<strong>' . (int) $m['numero'] . '</strong><small>' . $e(Numero::reais((string) $m['comanda_total'])) . '</small>'; require __DIR__ . '/../caixa/_mesa-desenho.php'; ?>
                            <span class="mesa-rodape">
                                <?php if ((int) $m['comandas'] > 1): ?>
                                    <span class="mesa-estado"><?= Icon::svg('users', 'icon-sm') ?><?= (int) $m['comandas'] ?> comandas</span>
                                <?php else: ?>
                                    <span class="mesa-estado"><?= Icon::svg('utensils', 'icon-sm') ?>Ocupada</span>
                                <?php endif; ?>
                                <span class="mesa-info"><?= Icon::svg('clock', 'icon-sm') ?><?= $e($tempo((string) $m['comanda_desde'])) ?></span>
                            </span>
                            <?php if ($m['apelido']): ?><span class="mesa-apelido"><?= $e((string) $m['apelido']) ?></span><?php endif; ?>
                            <?php $nomes = array_values(array_filter(array_map(static fn (array $cm): string => trim((string) ($cm['comanda_nome'] ?? '')), $m['lista_comandas']))); ?>
                            <?php if ($nomes !== []): ?><span class="mesa-nomes" title="<?= $e(implode(', ', $nomes)) ?>"><?= Icon::svg('user', 'icon-sm') ?><?= $e(implode(', ', $nomes)) ?></span><?php endif; ?>
                        </a>
                    <?php else: ?>
                        <form method="post" action="<?= $e($base . '/mesas/' . (int) $m['id'] . '/abrir') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="mesa is-livre" type="submit" aria-label="Abrir a mesa <?= (int) $m['numero'] ?>">
                                <?php $centro = '<strong>' . (int) $m['numero'] . '</strong>'; require __DIR__ . '/../caixa/_mesa-desenho.php'; ?>
                                <span class="mesa-rodape">
                                    <span class="mesa-estado"><?= Icon::svg('plus', 'icon-sm') ?>Abrir</span>
                                    <?php if ($lugares): ?><span class="mesa-info"><?= Icon::svg('users', 'icon-sm') ?><?= $lugares ?></span><?php endif; ?>
                                </span>
                                <?php if ($m['apelido']): ?><span class="mesa-apelido"><?= $e((string) $m['apelido']) ?></span><?php endif; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="garcom-nenhuma" data-mesas-nenhuma hidden>Nenhuma mesa aqui.</p>
    <?php endif; ?>
</div>
