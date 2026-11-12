<?php
/**
 * Entregador do iFood no pedido: etapa (a caminho, chegou, pegou), nome, telefone, veiculo e codigo de coleta.
 * Espera (de delivery-ver.php): $p, $ifoodDados, $status, $e.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Ifood\EventosIfood;

$ent = is_array($ifoodDados['entregador'] ?? null) ? $ifoodDados['entregador'] : null;
$etapaEnt = (string) ($ent['etapa'] ?? '');
$veiculos = ['MOTORCYCLE' => 'Moto', 'MOTORBIKE' => 'Moto', 'BICYCLE' => 'Bicicleta', 'BIKE' => 'Bicicleta', 'CAR' => 'Carro', 'WALKING' => 'A pé', 'ON_FOOT' => 'A pé'];
$veiculo = $ent !== null && !empty($ent['veiculo']) ? ($veiculos[strtoupper((string) $ent['veiculo'])] ?? (string) $ent['veiculo']) : null;
$chegou = $etapaEnt === 'ARRIVED_AT_ORIGIN' && in_array($status, ['recebido', 'em_preparo', 'pronto'], true);
?>
<section class="ifood-entrega<?= $chegou ? ' is-chegou' : '' ?>" aria-labelledby="titulo-entregador-ifood">
    <h3 id="titulo-entregador-ifood"><?= Icon::svg('bike', 'icon-sm') ?>Entregador do iFood</h3>
    <?php if ($ent === null || $etapaEnt === 'DELIVERY_DRIVER_DEALLOCATED'): ?>
        <p class="texto-suave"><?= $etapaEnt === 'DELIVERY_DRIVER_DEALLOCATED' ? 'O iFood está trocando o entregador.' : 'O iFood chama o entregador.' ?> Os dados aparecem aqui quando ele aceitar a corrida.</p>
    <?php else: ?>
        <p class="ifood-etapa" role="status"><?= Icon::svg($chegou ? 'bell' : 'map-pin', 'icon-sm') ?><strong><?= $e(EventosIfood::ETAPAS[$etapaEnt] ?? 'Entregador definido') ?></strong>
            <?php if (!empty($ent['etapa_em'])): ?><span class="texto-suave">às <?= $e(date('H:i', (int) strtotime((string) $ent['etapa_em']))) ?></span><?php endif; ?></p>
        <dl class="ifood-dados">
            <?php if (!empty($ent['nome'])): ?><div><dt>Nome</dt><dd><?= $e((string) $ent['nome']) ?></dd></div><?php endif; ?>
            <?php if (!empty($ent['telefone'])): ?><div><dt>Telefone</dt><dd><a class="link-acao" href="tel:<?= $e((string) preg_replace('/[^0-9+]/', '', (string) $ent['telefone'])) ?>"><?= Icon::svg('phone', 'icon-sm') ?><?= $e((string) $ent['telefone']) ?></a></dd></div><?php endif; ?>
            <?php if ($veiculo !== null): ?><div><dt>Veículo</dt><dd><?= $e($veiculo) ?></dd></div><?php endif; ?>
        </dl>
    <?php endif; ?>
    <?php if (!empty($p['ifood_codigo_coleta']) && in_array($status, ['recebido', 'em_preparo', 'pronto'], true)): ?>
        <div class="ifood-codigo">
            <span>Código de coleta</span>
            <strong><?= $e((string) $p['ifood_codigo_coleta']) ?></strong>
            <small>Peça ao entregador e confira antes de entregar o pedido.</small>
        </div>
    <?php endif; ?>
</section>
