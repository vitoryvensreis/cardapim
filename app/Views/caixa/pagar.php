<?php
/**
 * Receber a conta: uma ou varias formas, troco calculado na hora e divisao por pessoa.
 * Espera: $c (ComandaService::detalhes), $caixaAberto, $base, $csrf, $erros, $enviado.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$p = $c['pedido'];
$url = $base . '/comandas/' . (int) $p['id'];
$pessoas = (int) ($p['pessoas'] ?? 0);
$porPessoa = $pessoas > 1 ? intdiv($c['total'] + $pessoas - 1, $pessoas) : 0;
$forma = (string) ($enviado['forma'] ?? 'dinheiro');
$icones = ['dinheiro' => 'banknote', 'debito' => 'credit-card', 'credito' => 'credit-card', 'pix' => 'qr-code'];
?>
<div class="painel-page painel-page-form pagar-pagina">
    <header class="painel-cabecalho">
        <div>
            <h1>Receber · <?= $e($c['rotulo']) ?></h1>
            <p>Pedido #<?= (int) $p['numero'] ?>. Pode receber em mais de uma forma (parte no cartão, parte no dinheiro).</p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= $e($url) ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Voltar à conta</a>
    </header>

    <?php if (is_array($trocoAviso ?? null)): ?>
        <div class="troco-destaque" role="status">
            <?= Icon::svg('hand-coins', 'icon-lg') ?>
            <div><span>Devolva de troco</span><strong><?= $e($r((int) $trocoAviso['troco'])) ?></strong>
                <small>O cliente entregou <?= $e($r((int) $trocoAviso['recebido'])) ?></small></div>
        </div>
    <?php endif; ?>
    <div class="pagar-falta" role="status">
        <span>Falta pagar</span>
        <strong data-pagar-restante="<?= (int) $c['restante'] ?>"><?= $e($r($c['restante'])) ?></strong>
        <small>Total da conta <?= $e($r($c['total'])) ?><?= $c['pago'] > 0 ? ' · já pago ' . $e($r($c['pago'])) : '' ?><?= $porPessoa > 0 ? ' · ' . $pessoas . ' pessoas, ' . $e($r($porPessoa)) . ' cada' : '' ?></small>
    </div>

    <?php if (!$caixaAberto): ?>
        <div class="notice notice-warning bloco-aviso" role="alert">
            <?= Icon::svg('lock') ?>
            <div><strong>O caixa está fechado</strong><p>Abra o caixa para receber. <a href="<?= $e($base) ?>">Abrir caixa</a></p></div>
        </div>
    <?php else: ?>
        <form method="post" action="<?= $e($url . '/pagamentos') ?>" class="bloco pagar-form" novalidate data-pagar>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <fieldset class="formas-grade">
                <legend>Como o cliente vai pagar?</legend>
                <?php foreach (ComandaService::FORMAS_PRESENCIAIS as $chave => $rotulo): ?>
                    <label class="forma-opcao">
                        <input type="radio" name="forma" value="<?= $e($chave) ?>"<?= $forma === $chave ? ' checked' : '' ?> data-pagar-forma>
                        <span><?= Icon::svg($icones[$chave]) ?><?= $e($rotulo) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="campos-linha">
                <label class="campo-caixa campo-dinheiro"><span>Valor desta forma</span>
                    <input name="valor" inputmode="numeric" data-mask="money" value="<?= $e($enviado['valor'] ?? Numero::dinheiroCampo(CaixaService::dec($c['restante']))) ?>" data-pagar-valor<?= isset($erros['valor']) ? ' aria-invalid="true"' : '' ?>>
                </label>
                <label class="campo-caixa campo-dinheiro" data-pagar-recebido-campo><span>Cliente entregou</span>
                    <input name="recebido" inputmode="numeric" data-mask="money" placeholder="Opcional" value="<?= $e($enviado['recebido'] ?? '') ?>" data-pagar-recebido<?= isset($erros['recebido']) ? ' aria-invalid="true"' : '' ?>>
                </label>
            </div>
            <?php foreach (['valor', 'recebido', 'forma'] as $campo): if (isset($erros[$campo])): ?><p class="campo-erro"><?= $e($erros[$campo]) ?></p><?php endif; endforeach; ?>
            <div class="troco-ao-vivo" data-pagar-troco aria-live="polite" hidden>
                <span data-pagar-troco-rotulo>Troco</span>
                <strong data-pagar-troco-valor>R$ 0,00</strong>
            </div>

            <div class="pagar-atalhos" data-pagar-atalhos>
                <button type="button" class="chip" data-pagar-preencher="<?= (int) $c['restante'] ?>">Tudo (<?= $e($r($c['restante'])) ?>)</button>
                <?php if ($porPessoa > 0 && $porPessoa < $c['restante']): ?><button type="button" class="chip" data-pagar-preencher="<?= (int) $porPessoa ?>">Uma pessoa (<?= $e($r($porPessoa)) ?>)</button><?php endif; ?>
                <?php if ($c['restante'] > 1): ?><button type="button" class="chip" data-pagar-preencher="<?= (int) intdiv($c['restante'] + 1, 2) ?>">Metade</button><?php endif; ?>
            </div>
            <div class="pagar-notas" data-pagar-notas hidden>
                <span>Entregou:</span>
                <?php foreach ([2000, 5000, 10000, 20000] as $nota): if ($nota >= $c['restante']): ?>
                    <button type="button" class="chip" data-pagar-nota="<?= $nota ?>"><?= $e($r($nota)) ?></button>
                <?php endif; endforeach; ?>
            </div>

            <button class="btn btn-primary btn-lg btn-block" type="submit" data-pagar-enviar><?= Icon::svg('check', 'icon-sm') ?><span data-pagar-enviar-texto>Receber</span></button>
        </form>
    <?php endif; ?>

    <?php if ($c['pagamentos'] !== []): ?>
        <section class="bloco" aria-labelledby="titulo-pagos">
            <div class="bloco-cabecalho"><h2 id="titulo-pagos"><?= Icon::svg('receipt') ?>Já recebido</h2></div>
            <ul class="caixa-linhas">
                <?php foreach ($c['pagamentos'] as $pg): ?>
                    <li>
                        <span><strong><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?></strong>
                            <small><?= $e(date('H:i', (int) strtotime((string) $pg['criado_em']))) ?><?= Numero::centavos((string) $pg['troco']) > 0 ? ' · troco ' . $e(Numero::reais((string) $pg['troco'])) : '' ?><?= $pg['autor'] ? ' · ' . $e((string) $pg['autor']) : '' ?></small></span>
                        <span class="caixa-linha-fim">
                            <span class="caixa-valor"><?= $e(Numero::reais((string) $pg['valor'])) ?></span>
                            <form method="post" action="<?= $e($url . '/pagamentos/' . (int) $pg['id'] . '/remover') ?>" data-confirm="O valor volta para o que falta pagar." data-confirm-titulo="Remover este pagamento?" data-confirm-acao="Remover" data-confirm-perigo="1">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="btn btn-ghost btn-sm area-remover" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Remover</button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
