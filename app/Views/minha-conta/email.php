<?php
/** Confirmar codigo enviado por e-mail para ligar a verificacao. Espera: $email (mascarado), $erro, $base, $csrf. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/minha-conta#duas-etapas') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Minha conta</a>
            <h1>Ligar código por e-mail</h1>
            <p>Para confirmar que o e-mail chega até você, digite o código que enviamos para <strong><?= $e($email) ?></strong>.</p>
        </div>
    </header>

    <?php if (is_string($erro) && $erro !== ''): ?>
        <div class="notice notice-warning erros-form" role="alert"><?= Icon::svg('circle-x') ?><div><strong>Não deu certo</strong><p><?= $e($erro) ?></p></div></div>
    <?php endif; ?>

    <section class="bloco">
        <form method="post" action="<?= $e($base . '/minha-conta/dois-fatores/email/confirmar') ?>" class="form-codigo" novalidate data-validate-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="field codigo-6" data-field>
                <label for="codigo-email">Código de 6 números</label>
                <div class="field-control sem-icone">
                    <input id="codigo-email" name="codigo" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" placeholder="000000" required autofocus data-validate="required" aria-describedby="codigo-email-msg">
                </div>
                <p class="field-msg" id="codigo-email-msg">Vale por 10 minutos. Não chegou? Olhe a caixa de spam.</p>
            </div>
            <button class="btn btn-primary" type="submit" data-submit-label="Conferindo..."><?= Icon::svg('shield-check', 'icon-sm') ?>Ligar verificação</button>
        </form>
        <form class="bloco-rodape" method="post" action="<?= $e($base . '/minha-conta/dois-fatores/email') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="link-acao" type="submit"><?= Icon::svg('mail', 'icon-sm') ?>Enviar outro código</button>
        </form>
    </section>
</div>
