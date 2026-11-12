<?php
/**
 * Fila de delivery colada no menu lateral: do pedido mais antigo para o mais novo.
 * Espera: $filaDelivery, $slug. Tambem servida sozinha em /caixa/delivery/fila (atualizacao sem recarregar).
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e ??= static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$etapas = [
    'aguardando_pagamento' => ['Esperando Pix', 'etapa-espera', 'clock'],
    'recebido' => ['Novo', 'etapa-novo', 'bell'],
    'em_preparo' => ['Em preparo', 'etapa-preparo', 'chef-hat'],
    'pronto' => ['Pronto', 'etapa-pronto', 'package-check'],
    'saiu_entrega' => ['Saiu', 'etapa-rota', 'bike'],
];
$minutos = static function (string $quando): string {
    $m = max(0, (int) floor((time() - (int) strtotime($quando)) / 60));
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
?>
<section class="fila" aria-labelledby="fila-titulo" data-fila data-fila-url="/<?= $e(rawurlencode($slug)) ?>/caixa/delivery/fila">
    <h2 class="fila-titulo" id="fila-titulo"><?= Icon::svg('bike', 'icon-sm') ?>Pedidos de delivery <span class="fila-contagem" data-fila-contagem><?= count($filaDelivery) ?></span></h2>
    <?php if ($filaDelivery === []): ?>
        <p class="fila-vazia">Nenhum pedido em andamento. Os novos pedidos do cardápio aparecem aqui na hora.</p>
    <?php else: ?>
        <ol class="fila-lista">
            <?php foreach ($filaDelivery as $p): [$rotulo, $classe, $icone] = $etapas[$p['status']] ?? ['', '', 'circle']; ?>
                <li>
                    <a class="fila-item <?= $e($classe) ?>" href="/<?= $e(rawurlencode($slug)) ?>/caixa/delivery/<?= (int) $p['id'] ?>" data-fila-id="<?= (int) $p['id'] ?>" data-fila-status="<?= $e((string) $p['status']) ?>">
                        <span class="fila-linha">
                            <strong>#<?= (int) $p['numero'] ?><?php if (($p['origem'] ?? '') === 'ifood'): ?> <span class="selo-ifood">iFood</span><?php endif; ?></strong>
                            <span class="fila-etapa"><?= Icon::svg($icone, 'icon-sm') ?><?= $e($rotulo) ?></span>
                        </span>
                        <span class="fila-linha fila-sub">
                            <span><?= $e(mb_strimwidth((string) ($p['cliente_nome'] ?? 'Cliente'), 0, 22, '…')) ?> · <?= $p['tipo'] === 'entrega' ? $e((string) ($p['entrega_bairro'] ?? 'Entrega')) : 'Retirada' ?></span>
                            <span><?= Icon::svg('clock', 'icon-sm') ?><?= $e($minutos((string) $p['criado_em'])) ?></span>
                        </span>
                        <span class="fila-linha fila-sub"><span><?= $e(Numero::reais((string) $p['total'])) ?></span><?php if (trim((string) $p['entregador']) !== ''): ?><span><?= $e(explode(' ', (string) $p['entregador'])[0]) ?></span><?php endif; ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
