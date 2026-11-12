<?php
/** Pedir o link para redefinir a senha. Espera: $csrf, $erro, $enviado. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$asideTitle = 'Volte a entrar em poucos minutos.';
$asideItems = [
    ['mail', 'Link no seu e-mail', 'Mandamos um link para você criar uma senha nova.'],
    ['clock', 'Vale por 1 hora', 'O link funciona uma única vez, por segurança.'],
    ['users', 'Equipe sem e-mail', 'Caixa, garçom e entregador sem e-mail pedem a senha nova ao gerente.'],
];
?>
<section class="auth" aria-labelledby="recuperar-title">
    <div class="shell auth-layout">
        <?php require __DIR__ . '/../partials/auth-aside.php'; ?>
        <div class="auth-card" data-reveal>
            <?php if (!empty($enviado)): ?>
                <h1 id="recuperar-title">Confira seu e-mail.</h1>
                <div class="notice notice-success" role="status">
                    <?= Icon::svg('mail') ?>
                    <div>
                        <strong>Pedido recebido</strong>
                        <p>Se a conta existir e tiver e-mail cadastrado, enviamos um link para criar uma senha nova. Ele vale por 1 hora.</p>
                    </div>
                </div>
                <ul class="auth-passos">
                    <li><?= Icon::svg('inbox', 'icon-sm') ?>Procure o e-mail "Redefinir sua senha". Pode levar alguns minutos.</li>
                    <li><?= Icon::svg('triangle-exclamation-point', 'icon-sm') ?>Não achou? Confira a caixa de spam ou lixo eletrônico.</li>
                    <li><?= Icon::svg('users', 'icon-sm') ?>A sua conta não tem e-mail? Peça ao gerente do restaurante para criar uma senha nova.</li>
                </ul>
                <a class="btn btn-wine btn-lg btn-block" href="/login"><?= Icon::svg('log-in') ?><span>Voltar para entrar</span></a>
                <p class="auth-switch"><a href="/recuperar-senha">Pedir de novo</a></p>
            <?php else: ?>
                <h1 id="recuperar-title">Esqueceu a senha?</h1>
                <p class="auth-lead">Digite o e-mail ou o nome de usuário da sua conta. Enviamos um link para você criar uma senha nova.</p>

                <?php if (!empty($erro)): ?>
                    <div class="notice notice-warning" role="alert">
                        <?= Icon::svg('circle-x') ?>
                        <div><strong>Não deu certo</strong><p><?= $e($erro) ?></p></div>
                    </div>
                <?php endif; ?>

                <form class="auth-form" method="post" action="/recuperar-senha" novalidate data-validate-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <div class="field" data-field>
                        <label for="recuperar-identificacao">E-mail ou usuário</label>
                        <div class="field-control">
                            <?= Icon::svg('user', 'field-icon') ?>
                            <input id="recuperar-identificacao" name="identificacao" type="text" placeholder="seu@email.com" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="190" required autofocus aria-describedby="recuperar-identificacao-msg">
                        </div>
                        <p class="field-msg" id="recuperar-identificacao-msg" aria-live="polite"></p>
                    </div>
                    <button class="btn btn-wine btn-lg btn-block" type="submit" data-submit-label="Enviando...">
                        <?= Icon::svg('send') ?><span>Enviar link</span>
                    </button>
                </form>
                <p class="auth-help"><?= Icon::svg('info', 'icon-sm') ?>Caixa, garçom e entregador sem e-mail: peça ao gerente para criar uma senha nova.</p>
                <p class="auth-switch">Lembrou a senha? <a href="/login">Entrar <?= Icon::svg('arrow-right', 'icon-sm') ?></a></p>
            <?php endif; ?>
        </div>
    </div>
</section>
