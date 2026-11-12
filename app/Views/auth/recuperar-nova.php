<?php
/** Criar a senha nova pelo link do e-mail. Espera: $csrf, $invalido, $usuario, $erros. */
use Cardapim\Core\Icon;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$erros ??= [];
$asideTitle = 'Uma senha nova, e pronto.';
$asideItems = [
    ['key-round', 'Senha forte', 'Use 8 ou mais caracteres, misturando letras e números.'],
    ['smartphone', 'Aparelhos desconectados', 'Onde você estava conectado vai pedir a senha nova.'],
    ['shield-check', 'Verificação mantida', 'Se a sua conta tem verificação em duas etapas, ela continua valendo.'],
];
$campo = static function (string $nome, string $rotulo, string $auto, bool $forca, string $dica) use ($e, $erros): string {
    $id = 'nova-' . $nome;
    $erro = $erros[$nome] ?? null;
    ob_start(); ?>
    <div class="field<?= $erro !== null ? ' is-invalid' : '' ?>" data-field>
        <label for="<?= $id ?>"><?= $e($rotulo) ?></label>
        <div class="field-control">
            <?= Icon::svg('lock', 'field-icon') ?>
            <input id="<?= $id ?>" name="<?= $nome ?>" type="password" autocomplete="<?= $auto ?>" minlength="8" maxlength="128" required data-validate="password" aria-describedby="<?= $id ?>-msg"<?= $erro !== null ? ' aria-invalid="true"' : '' ?>>
            <button class="field-action" type="button" data-toggle-password aria-label="Mostrar senha" aria-pressed="false">
                <?= Icon::svg('eye', 'icon-show') ?><?= Icon::svg('eye-off', 'icon-hide') ?>
            </button>
        </div>
        <?php if ($forca): ?><div class="strength" data-strength aria-hidden="true"><span></span><span></span><span></span><span></span></div><?php endif; ?>
        <p class="field-msg" id="<?= $id ?>-msg" aria-live="polite" data-hint="<?= $e($dica) ?>"><?= $e($erro ?? $dica) ?></p>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<section class="auth" aria-labelledby="nova-title">
    <div class="shell auth-layout">
        <?php require __DIR__ . '/../partials/auth-aside.php'; ?>
        <div class="auth-card" data-reveal>
            <?php if (!empty($invalido)): ?>
                <h1 id="nova-title">Este link não vale mais.</h1>
                <div class="notice notice-warning" role="alert">
                    <?= Icon::svg('clock') ?>
                    <div>
                        <strong>Link vencido ou já usado</strong>
                        <p>O link vale por 1 hora e funciona uma única vez. Peça um novo: leva menos de um minuto.</p>
                    </div>
                </div>
                <a class="btn btn-wine btn-lg btn-block" href="/recuperar-senha"><?= Icon::svg('send') ?><span>Pedir um link novo</span></a>
                <p class="auth-switch"><a href="/login">Voltar para entrar</a></p>
            <?php else: ?>
                <h1 id="nova-title">Crie uma senha nova.</h1>
                <p class="auth-lead">Para a conta <strong><?= $e((string) $usuario) ?></strong>. Depois é só entrar com ela.</p>
                <?php if ($erros !== []): ?>
                    <div class="notice notice-warning" role="alert">
                        <?= Icon::svg('circle-x') ?>
                        <div><strong>Confira os campos destacados</strong></div>
                    </div>
                <?php endif; ?>
                <form class="auth-form" method="post" action="/recuperar-senha/nova" novalidate data-validate-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="text" name="usuario" value="<?= $e((string) $usuario) ?>" autocomplete="username" hidden>
                    <?= $campo('senha', 'Senha nova', 'new-password', true, 'Use 8 ou mais caracteres, misturando letras e números.') ?>
                    <?= $campo('senha_confirmacao', 'Repita a senha nova', 'new-password', false, 'Digite a mesma senha de novo.') ?>
                    <button class="btn btn-wine btn-lg btn-block" type="submit" data-submit-label="Salvando...">
                        <?= Icon::svg('check') ?><span>Salvar senha nova</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
