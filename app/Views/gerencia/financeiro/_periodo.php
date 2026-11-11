<?php
/**
 * Escolha do periodo (atalhos + datas). Funciona sem JavaScript (formulario GET).
 * Espera: $base, $periodo, $caminhoPeriodo (ex.: '/financeiro/extrato'), $extrasPeriodo (outros parametros a manter).
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\PeriodoFinanceiro;

$extrasPeriodo ??= [];
$linkPeriodo = static fn (array $q): string => htmlspecialchars($base . $caminhoPeriodo . '?' . http_build_query([...$q, ...$extrasPeriodo]), ENT_QUOTES, 'UTF-8');
?>
<section class="fin-periodo" aria-label="Período">
    <nav class="filtros" aria-label="Atalhos de período">
        <?php foreach (PeriodoFinanceiro::ATALHOS as $atalhoChave => $atalhoRotulo): ?>
            <a href="<?= $linkPeriodo(['periodo' => $atalhoChave]) ?>"<?= $periodo->chave === $atalhoChave ? ' aria-current="true"' : '' ?>><?= htmlspecialchars($atalhoRotulo, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="fin-datas" method="get" action="<?= htmlspecialchars($base . $caminhoPeriodo, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="periodo" value="personalizado">
        <?php foreach ($extrasPeriodo as $extraNome => $extraValor): ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $extraNome, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $extraValor, ENT_QUOTES, 'UTF-8') ?>">
        <?php endforeach; ?>
        <label><span>De</span><input type="date" name="de" value="<?= htmlspecialchars($periodo->de, ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>" required></label>
        <label><span>Até</span><input type="date" name="ate" value="<?= htmlspecialchars($periodo->ate, ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>" required></label>
        <button class="btn btn-outline btn-sm<?= $periodo->chave === 'personalizado' ? ' is-ativo' : '' ?>" type="submit"><?= Icon::svg('calendar-range', 'icon-sm') ?>Ver período</button>
    </form>
    <p class="fin-periodo-atual"><?= Icon::svg('calendar', 'icon-sm') ?>Mostrando: <strong><?= htmlspecialchars($periodo->rotulo(), ENT_QUOTES, 'UTF-8') ?></strong></p>
    <?php if ($periodo->aviso !== null): ?>
        <p class="notice notice-warning" role="status"><?= Icon::svg('triangle-exclamation-point') ?><span><?= htmlspecialchars($periodo->aviso, ENT_QUOTES, 'UTF-8') ?></span></p>
    <?php endif; ?>
</section>
