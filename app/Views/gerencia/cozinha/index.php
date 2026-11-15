<?php
use Cardapim\Core\Icon;
$e = static fn (string $valor): string => htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="kds-shell">
    <header class="kds-cabecalho">
        <div class="kds-identidade">
            <span class="kds-icone" aria-hidden="true"><?= Icon::svg('chef-hat', 'icon-lg') ?></span>
            <div>
                <h1>Cozinha</h1>
                <p><?= $e((string) $restaurante['nome']) ?> <span aria-hidden="true">·</span> Somente visualização</p>
            </div>
        </div>
        <div class="kds-acoes">
            <p class="kds-contador" aria-label="Pedidos aguardando preparo">
                <strong data-cozinha-total><?= (int) $fila['total'] ?></strong>
                <span data-cozinha-total-texto><?= $fila['total'] === 1 ? 'pedido' : 'pedidos' ?></span>
            </p>
            <a class="kds-voltar" href="<?= $e($base) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?><span>Voltar à gerência</span></a>
        </div>
    </header>
    <main class="kds-conteudo" id="conteudo">
        <div class="kds-faixa">
            <h2>Fila de preparo</h2>
            <p>Mais antigos primeiro <span aria-hidden="true">·</span> Atualização automática</p>
        </div>
        <div data-cozinha-board aria-live="off">
            <?php require __DIR__ . '/_pedidos.php'; ?>
        </div>
    </main>
</div>
