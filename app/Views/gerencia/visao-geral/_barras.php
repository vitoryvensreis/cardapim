<?php
/**
 * Grafico de barras em SVG gerado no servidor (sem biblioteca e sem estilo inline).
 * Espera: $barras (list<array{rotulo:string, valor:int, dica:string, destaque?:bool}>), $graficoTitulo, $graficoValor (callable int->string).
 * Acessivel: role="img" com resumo + lista escondida com todos os valores.
 */
$larguraSvg = 700;
$alturaSvg = 230;
$topo = 26;
$linhaBase = 196;
$qtd = max(1, count($barras));
$passo = $larguraSvg / $qtd;
$larguraBarra = min(56, $passo * 0.62);
$maior = max(1, ...array_column($barras, 'valor'));
$poucas = $qtd <= 10;
$esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<figure class="grafico">
    <svg class="grafico-svg<?= $poucas ? "" : " is-muitas" ?>" viewBox="0 0 <?= $larguraSvg ?> <?= $alturaSvg ?>" role="img" aria-label="<?= $esc($graficoTitulo) ?>" focusable="false">
        <line class="grafico-eixo" x1="0" y1="<?= $linhaBase ?>" x2="<?= $larguraSvg ?>" y2="<?= $linhaBase ?>"></line>
        <?php foreach ($barras as $i => $b):
            $altura = $b['valor'] > 0 ? max(3, ($linhaBase - $topo) * $b['valor'] / $maior) : 0;
            $x = $i * $passo + ($passo - $larguraBarra) / 2;
            $centro = $i * $passo + $passo / 2; ?>
            <g class="grafico-barra<?= !empty($b['destaque']) ? ' is-destaque' : '' ?>">
                <title><?= $esc($b['dica']) ?></title>
                <rect class="grafico-fundo" x="<?= round($i * $passo, 1) ?>" y="0" width="<?= round($passo, 1) ?>" height="<?= $linhaBase ?>"></rect>
                <?php if ($altura > 0): ?>
                    <rect class="grafico-valor" x="<?= round($x, 1) ?>" y="<?= round($linhaBase - $altura, 1) ?>" width="<?= round($larguraBarra, 1) ?>" height="<?= round($altura, 1) ?>" rx="2"></rect>
                    <?php if ($poucas): ?><text class="grafico-numero" x="<?= round($centro, 1) ?>" y="<?= round($linhaBase - $altura - 7, 1) ?>" text-anchor="middle"><?= $esc($graficoValor($b['valor'])) ?></text><?php endif; ?>
                <?php endif; ?>
                <text class="grafico-rotulo" x="<?= round($centro, 1) ?>" y="<?= $linhaBase + 22 ?>" text-anchor="middle"><?= $esc($b['rotulo']) ?></text>
            </g>
        <?php endforeach; ?>
    </svg>
    <ul class="sr-only">
        <?php foreach ($barras as $b): ?><li><?= $esc($b['dica']) ?></li><?php endforeach; ?>
    </ul>
</figure>
