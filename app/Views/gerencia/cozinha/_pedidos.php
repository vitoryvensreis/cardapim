<?php
/** Cards da cozinha: o mesmo fragmento serve à abertura e às atualizações. */
use Cardapim\Core\Icon;
use Cardapim\Modules\Inventory\QuantidadeVenda;

$e = static fn (string $valor): string => htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<?php if ($fila['pedidos'] === []): ?>
    <section class="kds-vazio" data-cozinha-vazio>
        <?= Icon::svg('circle-check', 'icon-lg') ?>
        <h3>Nenhum pedido para preparar</h3>
        <p>Assim que chegarem pedidos com itens para a cozinha, eles aparecem aqui automaticamente.</p>
    </section>
<?php else: ?>
    <ol class="kds-grade" aria-label="Pedidos em ordem de chegada">
        <?php foreach ($fila['pedidos'] as $pedido):
            $criado = strtotime((string) $pedido['criado_em']) ?: time();
            $mesa = $pedido['tipo'] === 'mesa';
            $status = (string) $pedido['status'];
            $local = match ((string) $pedido['tipo']) {
                'mesa' => 'Mesa ' . (string) ($pedido['mesa_numero'] ?? '—'),
                'balcao' => 'Balcão',
                'retirada' => 'Retirada',
                default => 'Entrega',
            };
            $etapa = match ($status) {
                'em_preparo' => 'Em preparo',
                'aberto' => 'Comanda aberta',
                default => 'Recebido',
            };
        ?>
        <li class="kds-card" data-pedido-id="<?= (int) $pedido['id'] ?>">
            <article aria-labelledby="pedido-<?= (int) $pedido['id'] ?>">
                <div class="kds-card-topo">
                    <div>
                        <p class="kds-numero" id="pedido-<?= (int) $pedido['id'] ?>">#<?= \Cardapim\Services\Pedidos\NumeroDoDia::de($pedido) ?></p>
                        <p class="kds-local"><?= $e($local) ?><?php if ($mesa && trim((string) ($pedido['comanda_nome'] ?? '')) !== ''): ?> · <?= $e((string) $pedido['comanda_nome']) ?><?php endif; ?></p>
                    </div>
                    <span class="kds-etapa <?= $status === 'recebido' ? 'is-novo' : '' ?>"><?= $e($etapa) ?></span>
                </div>
                <div class="kds-meta">
                    <span><?= Icon::svg('clock', 'icon-sm') ?><time datetime="<?= $e(date(DATE_ATOM, $criado)) ?>"><?= $e(date('H:i', $criado)) ?></time></span>
                    <span data-kds-tempo="<?= $criado ?>">há 0 min</span>
                    <?php if ($pedido['origem'] === 'ifood'): ?><span class="kds-origem">iFood<?= !empty($pedido['ifood_display_id']) ? ' #' . $e((string) $pedido['ifood_display_id']) : '' ?></span><?php endif; ?>
                </div>
                <ul class="kds-itens">
                    <?php foreach ($pedido['itens'] as $item): ?>
                    <li>
                        <strong class="kds-quantidade"><?= $e(QuantidadeVenda::textoDoBanco((string) $item['quantidade'], (string) ($item['unidade'] ?? 'un'))) ?></strong>
                        <div class="kds-item-descricao">
                            <strong class="kds-item-nome"><?= $e((string) $item['nome']) ?></strong>
                            <?php if (trim((string) ($item['complementos_texto'] ?? '')) !== ''): ?>
                                <p class="kds-complementos"><?= Icon::svg('square-plus', 'icon-sm') ?><span><?= $e((string) $item['complementos_texto']) ?></span></p>
                            <?php endif; ?>
                            <?php if (trim((string) ($item['observacao'] ?? '')) !== ''): ?>
                                <p class="kds-observacao"><?= Icon::svg('message-circle', 'icon-sm') ?><span><?= $e((string) $item['observacao']) ?></span></p>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (trim((string) ($pedido['observacao'] ?? '')) !== ''): ?>
                <div class="kds-nota-geral">
                    <?= Icon::svg('message-circle', 'icon-sm') ?>
                    <p><strong>Observação do pedido</strong><span><?= $e((string) $pedido['observacao']) ?></span></p>
                </div>
                <?php endif; ?>
            </article>
        </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
