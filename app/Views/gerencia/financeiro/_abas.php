<?php
/** Abas do Financeiro (mantem o periodo escolhido). Espera: $base, $abaFinanceiro, $periodo. */
use Cardapim\Core\Icon;

$qsAbas = '?' . $periodo->query();
$abasFinanceiro = [
    'resumo' => ['Resumo', 'chart-no-axes-column', '/financeiro' . $qsAbas],
    'extrato' => ['Entradas e saídas', 'arrow-right-left', '/financeiro/extrato' . $qsAbas],
    'contas' => ['Contas a pagar e receber', 'calendar-clock', '/financeiro/contas'],
    'fechamentos' => ['Fechamentos de caixa', 'lock', '/financeiro/fechamentos' . $qsAbas],
];
?>
<nav class="painel-abas" aria-label="Partes do financeiro">
    <?php foreach ($abasFinanceiro as $abaChave => [$abaRotulo, $abaIcone, $abaCaminho]): ?>
        <a href="<?= htmlspecialchars($base . $abaCaminho, ENT_QUOTES, 'UTF-8') ?>"<?= $abaFinanceiro === $abaChave ? ' aria-current="page"' : '' ?>>
            <?= Icon::svg($abaIcone, 'icon-sm') ?><?= $abaRotulo ?>
        </a>
    <?php endforeach; ?>
</nav>
