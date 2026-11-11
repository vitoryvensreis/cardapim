<?php
/**
 * Mapa das mesas: livre (toque para abrir a comanda) ou ocupada (total, itens e tempo). Vendas de balcao em aberto.
 * Espera: $mesas, $totalMesas, $balcoes, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tempo = static function (string $desde): string {
    $m = max(0, (int) floor((time() - (int) strtotime($desde)) / 60));
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
$ocupadas = count(array_filter($mesas, static fn (array $m): bool => $m['comanda_id'] !== null));
?>
<div class="painel-page" data-regiao="pagina-mesas">
    <header class="painel-cabecalho">
        <div>
            <h1>Mesas e comandas</h1>
            <p>Toque numa mesa livre para abrir a comanda. Mesas ocupadas mostram o total até agora.</p>
        </div>
        <div class="painel-acoes">
            <form method="post" action="<?= $e($base . '/balcao') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('store', 'icon-sm') ?>Venda no balcão</button>
            </form>
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/mesas/gerenciar') ?>"><?= Icon::svg('settings', 'icon-sm') ?>Cadastro de mesas</a>
        </div>
    </header>

    <?php if ($balcoes !== []): ?>
        <section class="bloco" aria-labelledby="titulo-balcao">
            <div class="bloco-cabecalho"><h2 id="titulo-balcao"><?= Icon::svg('store') ?>Balcão em aberto</h2></div>
            <ul class="balcoes">
                <?php foreach ($balcoes as $b): ?>
                    <li><a class="balcao-item" href="<?= $e($base . '/comandas/' . (int) $b['id']) ?>">
                        <strong>#<?= (int) $b['numero'] ?><?= $b['cliente_nome'] ? ' · ' . $e((string) $b['cliente_nome']) : '' ?></strong>
                        <span><?= $e(Numero::reais((string) $b['total'])) ?> · <?= $e($tempo((string) $b['criado_em'])) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($totalMesas === 0): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('armchair') ?>
                <strong>Nenhuma mesa cadastrada</strong>
                <p>Crie as mesas do salão de uma vez. Depois você pode dar nomes e mudar a quantidade de lugares.</p>
            </div>
            <form method="post" action="<?= $e($base . '/mesas/varias') ?>" class="mesas-rapido" novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <label>Criar da mesa <input name="de" value="1" inputmode="numeric" maxlength="4" aria-label="Primeira mesa"></label>
                <label>até a <input name="ate" value="10" inputmode="numeric" maxlength="4" aria-label="Última mesa"></label>
                <button class="btn btn-primary btn-sm" type="submit"><?= Icon::svg('plus', 'icon-sm') ?>Criar mesas</button>
            </form>
        </section>
    <?php elseif ($mesas === []): ?>
        <section class="bloco"><div class="lista-vazia"><?= Icon::svg('armchair') ?><strong>Todas as mesas estão pausadas</strong><p><a href="<?= $e($base . '/mesas/gerenciar') ?>">Ative as mesas no cadastro.</a></p></div></section>
    <?php else: ?>
        <div class="ferramentas">
            <nav class="filtros" aria-label="Filtrar mesas" data-mesas-filtro>
                <button type="button" aria-pressed="true" data-filtro-mesa="todas">Todas (<?= count($mesas) ?>)</button>
                <button type="button" aria-pressed="false" data-filtro-mesa="livre">Livres (<?= count($mesas) - $ocupadas ?>)</button>
                <button type="button" aria-pressed="false" data-filtro-mesa="ocupada">Ocupadas (<?= $ocupadas ?>)</button>
            </nav>
        </div>
        <ul class="mesas-grade" data-mesas>
            <?php foreach ($mesas as $m): $ocupada = $m['comanda_id'] !== null; $lugares = $m['lugares'] !== null ? (int) $m['lugares'] : null; ?>
                <li data-mesa-estado="<?= $ocupada ? 'ocupada' : 'livre' ?>">
                    <?php if ($ocupada): ?>
                        <a class="mesa is-ocupada" href="<?= $e($base . '/comandas/' . (int) $m['comanda_id']) ?>" aria-label="Mesa <?= (int) $m['numero'] ?>, ocupada, <?= $e(Numero::reais((string) $m['comanda_total'])) ?>">
                            <?php $centro = '<strong>' . (int) $m['numero'] . '</strong><small>' . $e(Numero::reais((string) $m['comanda_total'])) . '</small>'; require __DIR__ . '/_mesa-desenho.php'; ?>
                            <span class="mesa-rodape">
                                <span class="mesa-estado"><?= Icon::svg('utensils', 'icon-sm') ?>Ocupada</span>
                                <span class="mesa-info"><?= (int) $m['comanda_itens'] ?> <?= (int) $m['comanda_itens'] === 1 ? 'item' : 'itens' ?> · <?= Icon::svg('clock', 'icon-sm') ?><?= $e($tempo((string) $m['comanda_desde'])) ?></span>
                            </span>
                            <?php if ($m['apelido']): ?><span class="mesa-apelido"><?= $e((string) $m['apelido']) ?></span><?php endif; ?>
                        </a>
                    <?php else: ?>
                        <form method="post" action="<?= $e($base . '/mesas/' . (int) $m['id'] . '/abrir') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="mesa is-livre" type="submit" aria-label="Abrir comanda da mesa <?= (int) $m['numero'] ?>">
                                <?php $centro = '<strong>' . (int) $m['numero'] . '</strong>'; require __DIR__ . '/_mesa-desenho.php'; ?>
                                <span class="mesa-rodape">
                                    <span class="mesa-estado"><?= Icon::svg('circle-check', 'icon-sm') ?>Livre</span>
                                    <span class="mesa-info"><?= $lugares ? $lugares . ' lugares' : '' ?></span>
                                </span>
                                <?php if ($m['apelido']): ?><span class="mesa-apelido"><?= $e((string) $m['apelido']) ?></span><?php endif; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <li class="mesa-nova-item">
                <form method="post" action="<?= $e($base . '/mesas/nova') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="mesa mesa-nova" type="submit">
                        <span class="mesa-nova-mais" aria-hidden="true"><?= Icon::svg('plus', 'icon-lg') ?></span>
                        <span>Nova mesa</span>
                    </button>
                </form>
            </li>
        </ul>
        <p class="lista-vazia-filtro" data-mesas-nenhuma hidden>Nenhuma mesa nesta situação.</p>
    <?php endif; ?>
</div>
