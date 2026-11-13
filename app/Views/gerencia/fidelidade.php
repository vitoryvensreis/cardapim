<?php
/**
 * Clientes > Fidelidade: liga/desliga e configura o cartao fidelidade do cardapio online.
 * Espera: $valores, $erros, $config (salva), $numeros, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Descontos\Fidelidade;
use Cardapim\Services\Gerencia\FidelidadeService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $k): string => (string) ($valores[$k] ?? '');
$ligado = $config['ativo'];
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1>Fidelidade</h1>
            <p>Cliente que volta ganha desconto. Funciona nos pedidos do cardápio online, para clientes que entram com o WhatsApp.</p>
        </div>
        <?php if ($ligado): ?>
            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Programa ligado</span>
        <?php else: ?>
            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('circle-pause') ?>Programa desligado</span>
        <?php endif; ?>
    </header>

    <?php if ($ligado): ?>
        <section class="cl-destaque" aria-label="Como o cliente vê">
            <span class="cl-destaque-icone" aria-hidden="true"><?= Icon::svg('gift') ?></span>
            <div>
                <strong><?= $e(Fidelidade::regra($config)) ?></strong>
                <small><?= $e(FidelidadeService::exemplo($config)) ?> O cliente vê quanto falta na hora de finalizar o pedido.</small>
            </div>
        </section>
        <ul class="cl-numeros" aria-label="Números do programa">
            <li><span>Prêmios usados</span><strong><?= (int) $numeros['recompensas'] ?></strong></li>
            <li><span>Total descontado</span><strong><?= $e(Numero::reais($numeros['descontado'])) ?></strong></li>
            <li><span>Clientes que já pediram</span><strong><?= (int) $numeros['clientes'] ?></strong></li>
        </ul>
    <?php endif; ?>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($base . '/fidelidade') ?>" novalidate data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-programa">
            <div class="bloco-cabecalho"><h2 id="titulo-programa"><?= Icon::svg('gift') ?>Programa de fidelidade</h2></div>
            <?= Campo::alternador('ativo', 'Ligar o programa de fidelidade', 'Ligado: o cliente junta pedidos e ganha o desconto. Desligado: ninguém ganha, mas a contagem fica guardada.', $v('ativo') === '1') ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-regra">
            <div class="bloco-cabecalho">
                <h2 id="titulo-regra"><?= Icon::svg('repeat') ?>Quando o cliente ganha</h2>
                <p>Contam os pedidos <strong>entregues ou retirados</strong> (pedido cancelado não conta), inclusive os que ele já fez antes de você ligar.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'compras_necessarias', 'rotulo' => 'Quantos pedidos para ganhar', 'valor' => $v('compras_necessarias'), 'icone' => 'shopping-bag', 'sufixo' => 'pedidos',
                    'erro' => $erros['compras_necessarias'] ?? null, 'dica' => 'Ex.: 10. No pedido seguinte o desconto entra sozinho.',
                    'attrs' => ['inputmode' => 'numeric', 'maxlength' => 3]]) ?>
            </div>
            <fieldset class="opcoes-modo cl-opcoes" id="campo-quais">
                <legend class="rotulo-grupo">Quais pedidos contam</legend>
                <label class="opcao-modo">
                    <input type="radio" name="quais" value="todos"<?= $v('quais') !== 'minimo' ? ' checked' : '' ?>>
                    <span><strong>Pedidos de qualquer valor</strong><small>Todo pedido entregue ou retirado conta 1.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="quais" value="minimo"<?= $v('quais') === 'minimo' ? ' checked' : '' ?>>
                    <span><strong>Só pedidos a partir de um valor</strong><small>Vale o valor dos itens, sem a taxa de entrega.</small></span>
                </label>
            </fieldset>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'valor_minimo', 'rotulo' => 'Valor mínimo do pedido', 'valor' => $v('valor_minimo'), 'icone' => 'banknote', 'opcional' => true,
                    'erro' => $erros['valor_minimo'] ?? null, 'dica' => 'Só se escolher "a partir de um valor". Ex.: 30,00.',
                    'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00']]) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-premio">
            <div class="bloco-cabecalho"><h2 id="titulo-premio"><?= Icon::svg('percent') ?>O prêmio</h2></div>
            <fieldset class="opcoes-modo cl-opcoes cl-opcoes-2" id="campo-tipo">
                <legend class="rotulo-grupo">Tipo de desconto</legend>
                <label class="opcao-modo">
                    <input type="radio" name="tipo" value="percentual"<?= $v('tipo') !== 'fixo' ? ' checked' : '' ?>>
                    <span><strong><?= Icon::svg('percent', 'icon-sm') ?>Porcentagem</strong><small>Ex.: 10% do valor dos itens.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="tipo" value="fixo"<?= $v('tipo') === 'fixo' ? ' checked' : '' ?>>
                    <span><strong><?= Icon::svg('banknote', 'icon-sm') ?>Valor fixo</strong><small>Ex.: R$ 10,00 de desconto.</small></span>
                </label>
            </fieldset>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'valor', 'rotulo' => 'Valor do desconto', 'valor' => $v('valor'), 'icone' => 'tag',
                    'erro' => $erros['valor'] ?? null, 'dica' => 'Porcentagem: digite 10 para 10%. Valor fixo: digite 10,00.',
                    'attrs' => ['inputmode' => 'decimal', 'maxlength' => 12]]) ?>
            </div>
            <p class="texto-suave">O desconto vale sobre o valor dos itens (não sobre a taxa de entrega) e nunca passa do valor deles. Cupom e fidelidade não se somam: se o cliente usar um cupom, o prêmio fica guardado para o próximo pedido.</p>
        </section>

        <div class="form-acoes">
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar fidelidade</button>
        </div>
    </form>
</div>
