<?php
/**
 * Popup "Preparar pedido" (venda no balcao marcada para entrega): quando o cliente paga (agora ou na entrega),
 * a forma e o troco. Os dados da aba "Entrega" vao em campos escondidos preenchidos pelo caixa-entrega.js.
 * Envio normal (sem fetch): o servidor recalcula taxa e total e leva para o pedido na fila do delivery.
 * Espera: $url, $csrf, $e.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\EntregaBalcaoService;

$iconesForma = ['dinheiro' => 'banknote', 'pix' => 'qr-code', 'credito' => 'credit-card', 'debito' => 'credit-card'];
?>
<dialog class="folha preparar-pedido" data-dialogo-preparar aria-labelledby="preparar-titulo">
    <form method="post" action="<?= $e($url . '/entrega') ?>" class="folha-caixa" novalidate data-form-preparar>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?php foreach (['nome', 'telefone', 'cep', 'logradouro', 'numero', 'bairro', 'complemento'] as $campo): ?>
            <input type="hidden" name="<?= $e($campo) ?>" value="" data-preparar-campo="<?= $e($campo) ?>">
        <?php endforeach; ?>
        <div class="folha-topo">
            <h2 id="preparar-titulo">Preparar pedido de entrega</h2>
            <button class="folha-fechar" type="button" aria-label="Fechar" data-fechar-dialogo><?= Icon::svg('x') ?></button>
        </div>
        <div class="preparar-total">
            <span>Total com a entrega</span>
            <strong data-preparar-total>R$ 0,00</strong>
            <small data-preparar-destino></small>
        </div>

        <fieldset class="formas-grade preparar-quando">
            <legend>Quando o cliente paga?</legend>
            <label class="forma-opcao"><input type="radio" name="quando" value="agora" data-preparar-quando><span><?= Icon::svg('wallet') ?>Agora</span></label>
            <label class="forma-opcao"><input type="radio" name="quando" value="entrega" data-preparar-quando><span><?= Icon::svg('bike') ?>Na entrega</span></label>
        </fieldset>

        <fieldset class="formas-grade preparar-formas">
            <legend>Forma de pagamento</legend>
            <?php foreach (EntregaBalcaoService::FORMAS as $chave => $rotulo): ?>
                <label class="forma-opcao"><input type="radio" name="forma" value="<?= $e($chave) ?>" data-preparar-forma><span><?= Icon::svg($iconesForma[$chave]) ?><?= $e($rotulo) ?></span></label>
            <?php endforeach; ?>
        </fieldset>

        <div data-preparar-dinheiro hidden>
            <label class="campo-caixa"><span data-preparar-valor-rotulo>Cliente entregou</span>
                <input name="valor" inputmode="numeric" data-mask="money" placeholder="0,00" autocomplete="off" data-preparar-valor></label>
            <p class="campo-peso-ajuda" data-preparar-valor-ajuda>Deixe vazio se não precisar de troco.</p>
            <div class="troco-ao-vivo" data-preparar-troco aria-live="polite" hidden><span data-preparar-troco-rotulo>Troco</span><strong data-preparar-troco-valor></strong></div>
        </div>

        <p class="campo-erro" role="alert" data-preparar-erro hidden></p>
        <button class="btn btn-primary btn-lg btn-block" type="submit" data-preparar-enviar><?= Icon::svg('chef-hat', 'icon-sm') ?>Confirmar e enviar para a cozinha</button>
    </form>
</dialog>
