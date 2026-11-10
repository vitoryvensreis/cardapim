<?php
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$asideTitle = 'Sua operação inteira em uma tela.';
$asideItems = [
    ['receipt', 'Pedidos organizados', 'Salão, balcão e delivery na mesma fila.'],
    ['chef-hat', 'Cozinha conectada', 'A equipe vê o que preparar, na hora certa.'],
    ['shield-check', 'Acesso seguro', 'Cada pessoa vê só o que precisa para trabalhar.'],
];
?>
<section class="auth" aria-labelledby="login-title">
    <div class="shell auth-layout">
        <?php require __DIR__ . '/../partials/auth-aside.php'; ?>
        <div class="auth-card" data-reveal>
            <span class="kicker">Área do restaurante</span>
            <h1 id="login-title">Entre na sua conta.</h1>
            <p class="auth-lead">Use seu e-mail ou nome de usuário. Você será levado direto para a sua área.</p>

            <?php if (!empty($erro)): ?>
                <div class="notice notice-warning" role="alert">
                    <?= Icon::svg('circle-x') ?>
                    <div><strong>Não foi possível entrar</strong><p><?= $e($erro) ?></p></div>
                </div>
            <?php elseif (!empty($saiu)): ?>
                <div class="notice notice-success" role="status">
                    <?= Icon::svg('circle-check') ?>
                    <div><strong>Você saiu da sua conta.</strong><p>Até a próxima!</p></div>
                </div>
            <?php endif; ?>

            <form class="auth-form" method="post" action="/login" novalidate data-validate-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <div class="field" data-field>
                    <label for="login-identificacao">E-mail ou usuário</label>
                    <div class="field-control">
                        <?= Icon::svg('user', 'field-icon') ?>
                        <input id="login-identificacao" name="identificacao" type="text" value="<?= $e($identificacao) ?>" placeholder="seu@email.com" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="190" required aria-describedby="login-identificacao-msg"<?= empty($identificacao) ? ' autofocus' : '' ?>>
                    </div>
                    <p class="field-msg" id="login-identificacao-msg" aria-live="polite"></p>
                </div>
                <div class="field" data-field>
                    <label for="login-senha">Senha</label>
                    <div class="field-control">
                        <?= Icon::svg('lock', 'field-icon') ?>
                        <input id="login-senha" name="senha" type="password" placeholder="Digite sua senha" autocomplete="current-password" maxlength="1024" required aria-describedby="login-senha-msg"<?= !empty($identificacao) ? ' autofocus' : '' ?>>
                        <button class="field-action" type="button" data-toggle-password aria-label="Mostrar senha" aria-pressed="false">
                            <?= Icon::svg('eye', 'icon-show') ?><?= Icon::svg('eye-off', 'icon-hide') ?>
                        </button>
                    </div>
                    <p class="field-msg" id="login-senha-msg" aria-live="polite"></p>
                </div>
                <button class="btn btn-wine btn-lg btn-block" type="submit" data-submit-label="Entrando...">
                    <?= Icon::svg('log-in') ?><span>Entrar</span>
                </button>
            </form>
            <p class="auth-help"><?= Icon::svg('info', 'icon-sm') ?>Esqueceu a senha? Por enquanto, peça ao gerente do restaurante para redefinir.</p>
            <p class="auth-switch">Ainda não tem conta? <a href="/cadastro">Conheça o cadastro <?= Icon::svg('arrow-right', 'icon-sm') ?></a></p>
        </div>
    </div>
</section>
