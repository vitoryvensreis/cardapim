<?php
/**
 * Fechar a conta: total com os 10% (liga/desliga), dividir, forma de pagamento e troco.
 * Pix com QR Code aparece quando o restaurante tem o Asaas conectado. Conta paga = "Pago, mesa liberada".
 * Espera: $c, $caixaAberto, $pixDisponivel, $troco (flash), $erros, $enviado, $base, $csrf.
 */
use Cardapim\Controllers\GarcomController;
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $centavos): string => GarcomController::reais($centavos);
$p = $c['pedido'];
$url = $base . '/comandas/' . (int) $p['id'];
$servicoLigado = $c['servico'] > 0 || (float) $p['servico_percentual'] > 0;
$formaEscolhida = (string) ($enviado['forma'] ?? ($pixDisponivel ? 'pix_qr' : 'dinheiro'));
$formas = [
    'dinheiro' => ['Dinheiro', 'banknote', null],
    'debito' => ['Débito', 'credit-card', null],
    'credito' => ['Crédito', 'credit-card', null],
    'pix' => ['Pix', 'smartphone', 'chave ou maquininha'],
];
?>
<div class="garcom-pagina garcom-conta">
<?php if (!$c['aberta']): ?>
    <section class="gc-paga" aria-live="polite">
        <span class="gc-paga-icone" aria-hidden="true"><?= Icon::svg('circle-check', 'icon-lg') ?></span>
        <h1>Conta paga</h1>
        <p><?= $e((string) $c['rotulo']) ?> <?= ($p['tipo'] ?? '') === 'mesa' ? 'está livre.' : 'fechada.' ?></p>
        <?php if (is_array($troco)): ?>
            <div class="troco-destaque" role="status">
                <?= Icon::svg('hand-coins', 'icon-lg') ?>
                <div><span>Devolva de troco</span><strong><?= $e($r((int) $troco['troco'])) ?></strong><small>O cliente deu <?= $e($r((int) $troco['recebido'])) ?></small></div>
            </div>
        <?php endif; ?>
        <dl class="gc-paga-resumo">
            <div><dt>Total</dt><dd><?= $e($r($c['total'])) ?></dd></div>
            <?php foreach ($c['pagamentos'] as $pg): ?>
                <div><dt><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?></dt><dd><?= $e(Numero::reais((string) $pg['valor'])) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <a class="btn btn-primary btn-lg btn-block" href="<?= $e($base) ?>"><?= Icon::svg('layout-grid') ?>Voltar às mesas</a>
    </section>
<?php else: ?>
    <?php if (is_array($troco)): ?>
        <div class="troco-destaque" role="status">
            <?= Icon::svg('hand-coins', 'icon-lg') ?>
            <div><span>Devolva de troco</span><strong><?= $e($r((int) $troco['troco'])) ?></strong><small>O cliente deu <?= $e($r((int) $troco['recebido'])) ?></small></div>
        </div>
    <?php endif; ?>
    <section class="gc-total" aria-label="Valores da conta">
        <dl class="gc-linhas">
            <div><dt>Itens</dt><dd><?= $e($r($c['subtotal'])) ?></dd></div>
            <div class="gc-servico">
                <dt>
                    <form method="post" action="<?= $e($url . '/servico') ?>" class="servico-form" data-servico>
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="servico" value="<?= $servicoLigado ? '0' : '1' ?>">
                        <button type="submit" class="alternar" role="switch" aria-checked="<?= $servicoLigado ? 'true' : 'false' ?>">
                            <span class="alternar-trilho" aria-hidden="true"><span class="alternar-bolinha"></span></span>
                            <span>10% serviço</span>
                        </button>
                    </form>
                </dt>
                <dd><?= $servicoLigado ? $e($r($c['servico'])) : '<s>' . $e($r(\Cardapim\Services\Caixa\ComandaService::servico($c['subtotal'], '10.00'))) . '</s>' ?></dd>
            </div>
            <?php if ($c['desconto'] > 0): ?><div><dt>Desconto</dt><dd>- <?= $e($r($c['desconto'])) ?></dd></div><?php endif; ?>
            <?php if ($c['pago'] > 0): ?><div><dt>Já pago</dt><dd>- <?= $e($r($c['pago'])) ?></dd></div><?php endif; ?>
        </dl>
        <div class="gc-falta">
            <span><?= $c['pago'] > 0 ? 'Falta pagar' : 'Total' ?></span>
            <strong data-restante="<?= (int) $c['restante'] ?>"><?= $e($r($c['restante'])) ?></strong>
        </div>
        <div class="dividir" data-dividir hidden>
            <span><?= Icon::svg('split', 'icon-sm') ?>Dividir</span>
            <div class="passo passo-claro">
                <button type="button" class="passo-btn" data-dividir-menos aria-label="Menos pessoas"><?= Icon::svg('minus') ?></button>
                <output class="passo-num" data-dividir-pessoas aria-label="Pessoas">1</output>
                <button type="button" class="passo-btn" data-dividir-mais aria-label="Mais pessoas"><?= Icon::svg('plus') ?></button>
            </div>
            <strong class="dividir-cada" data-dividir-cada></strong>
        </div>
    </section>

    <?php if (!$caixaAberto): ?>
        <div class="notice notice-warning" role="alert">
            <?= Icon::svg('lock') ?>
            <div><strong>Caixa fechado</strong><p>Peça para abrir o caixa. Depois é só receber aqui.</p></div>
        </div>
    <?php else: ?>
        <fieldset class="gc-formas" data-formas>
            <legend>Como vai pagar?</legend>
            <?php if ($pixDisponivel): ?>
                <label class="gc-forma is-destaque">
                    <input type="radio" name="forma_tela" value="pix_qr"<?= $formaEscolhida === 'pix_qr' ? ' checked' : '' ?> data-forma>
                    <span><?= Icon::svg('qr-code') ?><b>QR Code Pix</b><small>na tela</small></span>
                </label>
            <?php endif; ?>
            <?php foreach ($formas as $chave => [$rotulo, $icone, $dica]): ?>
                <label class="gc-forma">
                    <input type="radio" name="forma_tela" value="<?= $e($chave) ?>"<?= $formaEscolhida === $chave ? ' checked' : '' ?> data-forma>
                    <span><?= Icon::svg($icone) ?><b><?= $e($rotulo) ?></b><?php if ($dica): ?><small><?= $e($dica) ?></small><?php endif; ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <?php if ($pixDisponivel): ?>
            <form method="post" action="<?= $e($url . '/pix') ?>" class="gc-pagar" data-painel-forma="pix_qr"<?= $formaEscolhida === 'pix_qr' ? '' : ' hidden' ?>>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <p class="gc-dica"><?= Icon::svg('smartphone', 'icon-sm') ?>O cliente aponta a câmera do app do banco para o QR Code. Quando pagar, a conta fecha sozinha.</p>
                <button class="btn btn-primary btn-lg btn-block" type="submit"><?= Icon::svg('qr-code') ?>Mostrar QR Code · <?= $e($r($c['restante'])) ?></button>
            </form>
        <?php endif; ?>

        <form method="post" action="<?= $e($url . '/pagamentos') ?>" class="gc-pagar" novalidate data-pagar-garcom data-painel-forma="presencial"<?= $formaEscolhida === 'pix_qr' ? ' hidden' : '' ?>>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="forma" value="<?= $e($formaEscolhida === 'pix_qr' ? 'dinheiro' : $formaEscolhida) ?>" data-forma-campo>
            <label class="campo-valor">
                <span>Valor</span>
                <input name="valor" inputmode="numeric" data-mask="money" value="<?= $e((string) ($enviado['valor'] ?? Numero::dinheiroCampo(CaixaService::dec($c['restante'])))) ?>" data-valor<?= isset($erros['valor']) ? ' aria-invalid="true"' : '' ?>>
            </label>
            <div class="chips-linha" data-atalhos-valor>
                <button type="button" class="chip" data-preencher="<?= (int) $c['restante'] ?>">Tudo</button>
                <button type="button" class="chip" data-preencher-pessoa hidden>1 pessoa</button>
            </div>
            <div class="dinheiro-campos" data-so-dinheiro>
                <label class="campo-valor">
                    <span>Cliente deu</span>
                    <input name="recebido" inputmode="numeric" data-mask="money" placeholder="0,00" value="<?= $e((string) ($enviado['recebido'] ?? '')) ?>" data-recebido<?= isset($erros['recebido']) ? ' aria-invalid="true"' : '' ?>>
                </label>
                <div class="chips-linha" data-notas>
                    <?php foreach ([1000, 2000, 5000, 10000, 20000] as $nota): if ($nota >= $c['restante']): ?>
                        <button type="button" class="chip" data-nota="<?= $nota ?>"><?= $e($r($nota)) ?></button>
                    <?php endif; endforeach; ?>
                </div>
                <div class="troco-ao-vivo" data-troco aria-live="polite" hidden>
                    <span data-troco-rotulo>Troco</span>
                    <strong data-troco-valor>R$ 0,00</strong>
                </div>
            </div>
            <?php foreach (['valor', 'recebido', 'forma'] as $campo): if (isset($erros[$campo])): ?><p class="campo-erro"><?= $e((string) $erros[$campo]) ?></p><?php endif; endforeach; ?>
            <button class="btn btn-wine btn-lg btn-block" type="submit" data-pagar-enviar><?= Icon::svg('check') ?><span data-pagar-texto>Recebi <?= $e($r($c['restante'])) ?></span></button>
        </form>
    <?php endif; ?>

    <?php if ($c['pagamentos'] !== []): ?>
        <section class="gc-pagos" aria-labelledby="titulo-pagos">
            <h2 id="titulo-pagos"><?= Icon::svg('receipt-text', 'icon-sm') ?>Já recebido</h2>
            <ul>
                <?php foreach ($c['pagamentos'] as $pg): ?>
                    <li><span><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?> · <?= $e(date('H:i', (int) strtotime((string) $pg['criado_em']))) ?></span><strong><?= $e(Numero::reais((string) $pg['valor'])) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php endif; ?>
</div>
