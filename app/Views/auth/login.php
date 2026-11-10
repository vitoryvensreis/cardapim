<?php
use Cardapim\Core\Icon;

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
            <p class="auth-lead">Acesse seu espaço de trabalho no Cardapim.</p>

            <div class="notice notice-info" role="status">
                <?= Icon::svg('info') ?>
                <div>
                    <strong>Login em preparação</strong>
                    <p>Esta é uma prévia da tela. Por enquanto, não é possível entrar com usuário e senha.</p>
                </div>
            </div>

            <form class="auth-form" method="post" novalidate data-preview-form aria-label="Prévia do formulário de login">
                <div class="field" data-field>
                    <label for="login-identificacao">E-mail ou usuário</label>
                    <div class="field-control">
                        <?= Icon::svg('user', 'field-icon') ?>
                        <input id="login-identificacao" name="identificacao" type="text" placeholder="seu@email.com" autocomplete="username" autocapitalize="none" spellcheck="false" required aria-describedby="login-identificacao-msg">
                    </div>
                    <p class="field-msg" id="login-identificacao-msg" aria-live="polite"></p>
                </div>
                <div class="field" data-field>
                    <label for="login-senha">Senha</label>
                    <div class="field-control">
                        <?= Icon::svg('lock', 'field-icon') ?>
                        <input id="login-senha" name="senha" type="password" placeholder="Digite sua senha" autocomplete="current-password" required aria-describedby="login-senha-msg">
                        <button class="field-action" type="button" data-toggle-password aria-label="Mostrar senha" aria-pressed="false">
                            <?= Icon::svg('eye', 'icon-show') ?><?= Icon::svg('eye-off', 'icon-hide') ?>
                        </button>
                    </div>
                    <p class="field-msg" id="login-senha-msg" aria-live="polite"></p>
                </div>
                <button class="btn btn-wine btn-lg btn-block" type="submit" disabled><?= Icon::svg('lock') ?>Entrar em breve</button>
            </form>
            <p class="auth-switch">Ainda não tem conta? <a href="/cadastro">Conheça o cadastro <?= Icon::svg('arrow-right', 'icon-sm') ?></a></p>
        </div>
    </div>
</section>
