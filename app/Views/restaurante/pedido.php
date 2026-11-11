<?php
/**
 * Acompanhar pedido (/{slug}/pedido/{id}). Os dados vem de GET /api/v1/pedidos/{id} (so para o dono do pedido)
 * e sao atualizados sozinhos enquanto o pedido nao termina.
 * Espera: $restaurante, $idPedido, $telefone.
 */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tel = preg_replace('/\D/', '', (string) $telefone);
$whats = strlen((string) $tel) >= 10 ? 'https://wa.me/55' . $tel : '';
?>
<div class="ck ck-pedido" data-acompanhar="<?= (int) $idPedido ?>">
    <div class="ck-carregando" data-ap-carregando role="status">
        <?= Icon::svg('loader-circle', 'icon icon-lg icon-girar') ?>
        <p>Carregando seu pedido...</p>
    </div>

    <div class="ck-vazio" data-ap-login hidden>
        <?= Icon::svg('circle-user', 'icon icon-lg') ?>
        <strong>Entre para ver seu pedido</strong>
        <p>Use o mesmo WhatsApp com que você fez o pedido.</p>
        <button class="btn btn-primary" type="button" data-ap-entrar>Entrar com WhatsApp</button>
    </div>

    <div class="ck-vazio" data-ap-erro hidden>
        <?= Icon::svg('circle-exclamation-point', 'icon icon-lg') ?>
        <strong data-ap-erro-titulo>Pedido não encontrado</strong>
        <p data-ap-erro-texto>Confira se entrou com o mesmo WhatsApp do pedido.</p>
        <a class="btn btn-outline" href="/<?= $e((string) $restaurante['slug']) ?>">Voltar ao cardápio</a>
    </div>

    <div class="ck-pedido-conteudo" data-ap-conteudo hidden>
        <section class="ck-status" data-ap-status>
            <span class="ck-status-icone" aria-hidden="true" data-ap-icone></span>
            <p class="ck-status-numero" data-ap-numero></p>
            <h1 data-ap-titulo></h1>
            <p class="ck-status-texto" data-ap-texto></p>
        </section>

        <section class="ck-bloco ap-mapa-bloco" data-ap-mapa hidden aria-labelledby="ap-mapa-titulo">
            <div class="ap-mapa-topo">
                <span class="ap-mapa-icone" aria-hidden="true"><?= Icon::svg('bike') ?></span>
                <div>
                    <h2 id="ap-mapa-titulo" data-ap-mapa-titulo>Entregador a caminho</h2>
                    <p data-ap-mapa-texto>Procurando o entregador no mapa...</p>
                </div>
            </div>
            <div class="ap-mapa" data-ap-mapa-area role="img" aria-label="Mapa com o entregador e o endereço de entrega" hidden></div>
            <p class="ap-mapa-atualizado" data-ap-mapa-atualizado></p>
        </section>

        <section class="ck-bloco ck-pix" data-ap-pix hidden aria-labelledby="ap-pix-titulo">
            <h2 id="ap-pix-titulo">Pague com Pix para confirmar</h2>
            <p class="ck-pix-tempo" data-ap-pix-tempo></p>
            <div class="ck-pix-qr" data-ap-pix-qr aria-label="QR Code do Pix" role="img"></div>
            <ol class="ck-pix-passos">
                <li>Abra o app do seu banco e escolha <strong>Pix</strong>.</li>
                <li>Leia o QR Code ou use <strong>Pix Copia e Cola</strong>.</li>
                <li>Pronto: a confirmação aparece aqui sozinha.</li>
            </ol>
            <label class="sr-only" for="ap-pix-codigo">Código Pix copia e cola</label>
            <textarea id="ap-pix-codigo" class="ck-pix-codigo" rows="3" readonly data-ap-pix-codigo></textarea>
            <button class="btn btn-primary btn-block" type="button" data-ap-copiar><?= Icon::svg('copy', 'icon-sm') ?><span data-ap-copiar-texto>Copiar código Pix</span></button>
        </section>

        <section class="ck-bloco" data-ap-etapas-bloco aria-label="Andamento do pedido">
            <ol class="ck-etapas" data-ap-etapas></ol>
        </section>

        <section class="ck-bloco" aria-labelledby="ap-detalhes-titulo">
            <h2 id="ap-detalhes-titulo">Detalhes</h2>
            <ul class="ck-itens" data-ap-itens></ul>
            <dl class="ck-totais">
                <div><dt>Subtotal</dt><dd data-ap-subtotal></dd></div>
                <div data-ap-linha-taxa><dt>Taxa de entrega</dt><dd data-ap-taxa></dd></div>
                <div class="ck-total"><dt>Total</dt><dd data-ap-total></dd></div>
            </dl>
            <dl class="ck-info">
                <div><dt><?= Icon::svg('wallet', 'icon-sm') ?>Pagamento</dt><dd data-ap-pagamento></dd></div>
                <div data-ap-linha-endereco><dt><?= Icon::svg('map-pin', 'icon-sm') ?><span data-ap-endereco-rotulo>Entregar em</span></dt><dd data-ap-endereco></dd></div>
                <div data-ap-linha-obs hidden><dt><?= Icon::svg('pencil', 'icon-sm') ?>Observação</dt><dd data-ap-obs></dd></div>
            </dl>
        </section>

        <div class="ck-pedido-acoes">
            <?php if ($whats !== ''): ?>
                <a class="btn btn-outline btn-block" href="<?= $e($whats) ?>" target="_blank" rel="noopener"><?= Icon::svg('message-circle', 'icon-sm') ?>Falar com o restaurante</a>
            <?php endif; ?>
            <a class="btn btn-outline btn-block" href="/<?= $e((string) $restaurante['slug']) ?>"><?= Icon::svg('utensils-crossed', 'icon-sm') ?>Voltar ao cardápio</a>
        </div>
    </div>
    <div class="cd-toast" data-toast role="status" aria-live="polite" hidden></div>
</div>
