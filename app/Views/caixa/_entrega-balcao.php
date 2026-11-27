<?php
/**
 * Aba "Entrega" da venda no balcao (dentro da Conta, logo depois da observacao). Desmarcada: so a caixinha e a venda
 * funciona como sempre. Marcada: telefone (Buscar), nome, rua, numero, bairro e complemento; o caixa-entrega.js busca
 * o bairro, calcula a taxa e troca "Receber e fechar conta" por "Preparar pedido" (popup _preparar-pedido.php).
 * Nada e gravado aqui: o servidor recalcula tudo no "Preparar pedido".
 * Espera: $c, $p, $url, $e.
 */
use Cardapim\Core\Icon;
?>
<details class="conta-mais conta-entrega" data-entrega data-entrega-id="<?= (int) $p['id'] ?>"
         data-url-telefone="<?= $e($url . '/entrega/telefone') ?>" data-url-bairro="<?= $e($url . '/entrega/bairro') ?>" data-url-calcular="<?= $e($url . '/entrega/calcular') ?>">
    <summary><?= Icon::svg('bike', 'icon-sm') ?>Entrega</summary>
    <div class="conta-ajustes entrega-corpo">
        <?php if ($c['pago'] > 0): ?>
            <p class="conta-aviso"><?= Icon::svg('info', 'icon-sm') ?>Esta venda já tem pagamento registrado. Para enviar como entrega, remova o pagamento.</p>
        <?php else: ?>
            <label class="alternador">
                <span><strong>Pedido para entrega</strong><small>Marque para mandar este pedido para a casa do cliente.</small></span>
                <input type="checkbox" data-entrega-ativo><span class="alternador-chave" aria-hidden="true"></span>
            </label>
            <div class="entrega-campos" data-entrega-campos hidden>
                <div class="entrega-telefone">
                    <label class="campo-caixa"><span>Telefone do cliente</span>
                        <input type="tel" inputmode="tel" maxlength="20" autocomplete="off" placeholder="(85) 99999-9999" data-entrega-campo="telefone"></label>
                    <button class="btn btn-outline" type="button" data-entrega-buscar><?= Icon::svg('search', 'icon-sm') ?>Buscar</button>
                </div>
                <label class="campo-caixa"><span>Nome</span>
                    <input maxlength="80" autocomplete="off" placeholder="Nome de quem recebe" data-entrega-campo="nome"></label>
                <label class="campo-caixa"><span>Rua</span>
                    <input maxlength="150" autocomplete="off" placeholder="Ex.: Rua Barão de Aracati" data-entrega-campo="logradouro"></label>
                <div class="campos-linha">
                    <label class="campo-caixa"><span>Número</span>
                        <input maxlength="20" autocomplete="off" inputmode="numeric" placeholder="Ex.: 120 ou S/N" data-entrega-campo="numero"></label>
                    <label class="campo-caixa"><span>Bairro</span>
                        <input maxlength="100" autocomplete="off" placeholder="Preenchido sozinho" data-entrega-campo="bairro"></label>
                </div>
                <div class="entrega-bairros" data-entrega-bairros hidden>
                    <p>Essa rua passa por mais de um bairro. Toque no certo:</p>
                    <div class="entrega-bairros-opcoes" data-entrega-bairros-opcoes></div>
                </div>
                <label class="campo-caixa"><span>Complemento <small>(opcional)</small></span>
                    <input maxlength="100" autocomplete="off" placeholder="Ex.: apto 202, casa dos fundos, perto da praça" data-entrega-campo="complemento"></label>
                <input type="hidden" data-entrega-campo="cep">
                <p class="entrega-status" data-entrega-status role="status" aria-live="polite" hidden></p>
                <dl class="entrega-resumo" data-entrega-resumo hidden>
                    <div><dt>Itens</dt><dd data-entrega-subtotal></dd></div>
                    <div data-entrega-linha-desconto hidden><dt>Desconto</dt><dd data-entrega-desconto></dd></div>
                    <div><dt>Taxa de entrega</dt><dd data-entrega-taxa></dd></div>
                    <div class="entrega-resumo-total"><dt>Total</dt><dd data-entrega-total></dd></div>
                </dl>
            </div>
        <?php endif; ?>
    </div>
</details>
