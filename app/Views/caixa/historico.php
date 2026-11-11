<?php
/** Historico de caixas (aberto e fechados). Espera: $caixas, $base. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (?string $d): string => $d ? date('d/m/Y H:i', (int) strtotime($d)) : '—';
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Fechamentos</h1>
            <p>Cada abertura e fechamento de caixa, com o que foi vendido e se sobrou ou faltou dinheiro.</p>
        </div>
    </header>
    <?php if ($caixas === []): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('rotate-ccw-clock') ?><strong>Nenhum caixa ainda</strong><p>Quando o primeiro caixa for aberto, ele aparece aqui.</p></div></section>
    <?php else: ?>
        <ul class="lista lista-caixas">
            <li class="lista-cabecalho" aria-hidden="true"><span>Caixa</span><span>Vendas</span><span>Dinheiro</span><span>Diferença</span><span class="lista-cab-acoes">Ver</span></li>
            <?php foreach ($caixas as $c):
                $resumo = json_decode((string) ($c['resumo'] ?? ''), true);
                $dif = $c['diferenca'] !== null ? Numero::centavos((string) $c['diferenca']) : null; ?>
                <li class="lista-item">
                    <span class="lista-principal">
                        <span class="lista-icone" aria-hidden="true"><?= Icon::svg($c['status'] === 'aberto' ? 'lock-open' : 'lock', 'icon-sm') ?></span>
                        <span><strong><?= $e($data((string) $c['aberto_em'])) ?></strong>
                            <small><?= $c['status'] === 'aberto' ? 'Aberto agora · ' . $e((string) $c['aberto_por']) : 'Fechado ' . $e($data($c['fechado_em'])) . ' · ' . $e((string) $c['fechado_por']) ?></small></span>
                    </span>
                    <span class="lista-dado" data-rotulo="Vendas"><?= is_array($resumo) ? $e(Numero::reais(\Cardapim\Services\Caixa\CaixaService::dec((int) $resumo['vendas']))) : '<span class="texto-suave">Em andamento</span>' ?></span>
                    <span class="lista-dado" data-rotulo="Dinheiro contado"><?= $c['dinheiro_contado'] !== null ? $e(Numero::reais((string) $c['dinheiro_contado'])) : '—' ?></span>
                    <span class="lista-dado" data-rotulo="Diferença">
                        <?php if ($dif === null): ?>—<?php elseif ($dif === 0): ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Certo</span>
                        <?php elseif ($dif > 0): ?><span class="etiqueta etiqueta-atencao"><?= Icon::svg('plus') ?>Sobrou <?= $e(Numero::reais(\Cardapim\Services\Caixa\CaixaService::dec(abs($dif)))) ?></span>
                        <?php else: ?><span class="etiqueta etiqueta-alerta"><?= Icon::svg('minus') ?>Faltou <?= $e(Numero::reais(\Cardapim\Services\Caixa\CaixaService::dec(abs($dif)))) ?></span><?php endif; ?>
                    </span>
                    <span class="lista-acoes"><a class="btn btn-outline btn-sm" href="<?= $e($base . ($c['status'] === 'aberto' ? '' : '/historico/' . (int) $c['id'])) ?>"><?= Icon::svg('eye', 'icon-sm') ?>Ver</a></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
