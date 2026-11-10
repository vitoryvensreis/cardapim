<?php
/** Segunda etapa do login. Espera: $csrf, $erro, $aviso, $metodo ('app' | 'email'). */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$porEmail = $metodo === 'email';
$asideTitle = 'Sua conta protegida em duas etapas.';
$asideItems = [
    ['shield-check', 'Mais segurança', 'Mesmo que alguém descubra sua senha, não consegue entrar sem o código.'],
    ['smartphone', 'Código no celular', 'O código muda sozinho a cada 30 segundos no aplicativo.'],
    ['key-round', 'Perdeu o celular?', 'Use um dos códigos de recuperação que você guardou.'],
];
?>
<section class="auth" aria-labelledby="verificacao-title">
    <div class="shell auth-layout">
        <?php require __DIR__ . '/../partials/auth-aside.php'; ?>
        <div class="auth-card" data-reveal>
            <span class="kicker">Verificação em duas etapas</span>
            <h1 id="verificacao-title">Confirme que é você.</h1>
            <p class="auth-lead">
                <?= $porEmail
                    ? 'Digite o código de 6 números que enviamos para o seu e-mail.'
                    : 'Abra o aplicativo autenticador (Google Authenticator, por exemplo) e digite o código de 6 números do Cardapim.' ?>
            </p>

            <?php if (!empty($erro)): ?>
                <div class="notice notice-warning" role="alert">
                    <?= Icon::svg('circle-x') ?>
                    <div><strong>Não deu certo</strong><p><?= $e($erro) ?></p></div>
                </div>
            <?php elseif (!empty($aviso)): ?>
                <div class="notice notice-info" role="status">
                    <?= Icon::svg('mail') ?>
                    <div><p><?= $e($aviso) ?></p></div>
                </div>
            <?php endif; ?>

            <form class="auth-form" method="post" action="/login/verificacao" novalidate data-validate-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="field codigo-6" data-field>
                    <label for="verificacao-codigo">Código de 6 números</label>
                    <div class="field-control">
                        <?= Icon::svg('shield-check', 'field-icon') ?>
                        <input id="verificacao-codigo" name="codigo" type="text" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code"
                               maxlength="7" placeholder="000000" required autofocus aria-describedby="verificacao-codigo-msg" data-validate="required">
                    </div>
                    <p class="field-msg" id="verificacao-codigo-msg" aria-live="polite"></p>
                </div>
                <button class="btn btn-primary btn-lg btn-block" type="submit" data-submit-label="Conferindo..."><?= Icon::svg('log-in') ?>Entrar</button>
            </form>

            <?php if ($porEmail): ?>
                <form class="auth-extra" method="post" action="/login/verificacao/reenviar">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <span>Não chegou? Veja também a caixa de spam.</span>
                    <button class="link-botao" type="submit">Enviar outro código</button>
                </form>
            <?php endif; ?>

            <details class="auth-recuperacao">
                <summary><?= Icon::svg('key-round', 'icon-sm') ?>Perdeu o acesso ao <?= $porEmail ? 'e-mail' : 'celular' ?>? Use um código de recuperação</summary>
                <form method="post" action="/login/verificacao">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <label for="verificacao-recuperacao">Código de recuperação (8 letras e números)</label>
                    <div class="linha-recuperacao">
                        <input id="verificacao-recuperacao" name="codigo" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="12" placeholder="abcd-efgh" required>
                        <button class="btn btn-wine" type="submit">Usar código</button>
                    </div>
                    <small>Cada código funciona uma vez só.</small>
                </form>
            </details>

            <p class="auth-switch"><a href="/login">Voltar e entrar com outra conta</a></p>
        </div>
    </div>
</section>
