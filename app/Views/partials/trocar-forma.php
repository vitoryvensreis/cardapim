<?php
/**
 * Botao e popup "Trocar forma de pagamento" (motoboy e caixa). Os valores vem do servidor (TrocarFormaPagamento::opcoes);
 * cada opcao envia "forma|taxa vista" e o servidor confere. trocar-forma.js abre o popup e mostra o "Receber".
 * Espera: $troca (opcoes), $trocaAcao (URL do POST), $trocaId (id unico do popup), $csrf, $trocaPedeCpf (Pix por QR sem CPF salvo).
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rs = static fn (int $c): string => Numero::reais(number_format($c / 100, 2, '.', ''));
$trocaPedeCpf = (bool) ($trocaPedeCpf ?? false);
$icones = ['dinheiro' => 'banknote', 'debito' => 'credit-card', 'credito' => 'credit-card', 'pix_entrega' => 'smartphone', 'pix_online' => 'qr-code'];
?>
<button type="button" class="btn btn-outline btn-block troca-abrir" data-trocar-forma-abrir="<?= $e($trocaId) ?>"><?= Icon::svg('arrow-left-right', 'icon-sm') ?>Trocar forma de pagamento</button>
<dialog class="folha troca-forma" id="<?= $e($trocaId) ?>" aria-labelledby="<?= $e($trocaId) ?>-titulo" data-troca-forma data-taxa-atual="<?= (int) $troca['taxa_atual'] ?>">
    <form method="post" action="<?= $e($trocaAcao) ?>" class="folha-caixa" data-troca-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <div class="folha-topo">
            <h2 id="<?= $e($trocaId) ?>-titulo">Trocar forma de pagamento</h2>
            <button type="button" class="folha-fechar" data-fechar-dialogo aria-label="Fechar"><?= Icon::svg('x') ?></button>
        </div>
        <p class="troca-atual">Agora: <strong><?= $e((string) $troca['forma_atual_rotulo']) ?></strong> · <?= $e($rs((int) $troca['base'] + (int) $troca['taxa_atual'])) ?></p>
        <fieldset class="troca-opcoes">
            <legend>Como o cliente vai pagar?</legend>
            <?php foreach ($troca['opcoes'] as $o): $mesma = $o['atual'] && $o['forma'] !== 'pix_online'; ?>
                <label class="troca-opcao<?= $mesma ? ' is-atual' : '' ?>">
                    <input type="radio" name="escolha" value="<?= $e($o['forma'] . '|' . (int) $o['taxa']) ?>" required<?= $mesma ? ' disabled' : '' ?>
                        data-forma="<?= $e((string) $o['forma']) ?>" data-taxa="<?= (int) $o['taxa'] ?>" data-total="<?= (int) $o['total'] ?>" data-taxa-rotulo="<?= $e((string) ($o['taxa_rotulo'] ?? '')) ?>">
                    <span><?= Icon::svg($icones[$o['forma']] ?? 'wallet') ?><strong><?= $e((string) $o['rotulo']) ?></strong>
                        <small><?= $mesma ? 'forma atual' : ($o['taxa'] > 0 ? '+ ' . $e($rs((int) $o['taxa'])) . ' de taxa' : 'sem taxa') ?></small></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <div class="troca-resumo" data-troca-resumo aria-live="polite" hidden>
            <p class="troca-linha" data-troca-taxa-linha hidden></p>
            <p class="troca-receber"><span>Receber</span><strong data-troca-total></strong></p>
            <p class="troca-dica" data-troca-qr-dica hidden><?= Icon::svg('qr-code', 'icon-sm') ?>Ao confirmar, aparece o QR Code para o cliente pagar. O "Entreguei" fica liberado quando o Pix cair.</p>
        </div>
        <label class="campo-caixa" data-troca-troco hidden><span>Troco para (opcional)</span>
            <input name="troco_para" inputmode="numeric" data-mask="money" placeholder="0,00" autocomplete="off"></label>
        <?php if ($trocaPedeCpf): ?>
            <label class="campo-caixa" data-troca-cpf hidden><span>CPF do cliente (o banco pede para gerar o Pix)</span>
                <input name="cpf" inputmode="numeric" data-mask="cpf" placeholder="000.000.000-00" autocomplete="off"></label>
        <?php endif; ?>
        <button class="btn btn-primary btn-lg btn-block" type="submit" data-troca-enviar><?= Icon::svg('check', 'icon-sm') ?>Confirmar troca</button>
    </form>
</dialog>
