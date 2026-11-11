<?php
use Cardapim\Core\Icon;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$asideTitle = 'Comece pelo seu restaurante. O resto a gente organiza.';
$asideItems = [
    ['clipboard-list', 'Mesas e comandas', 'Veja o salão inteiro e feche contas sem confusão.'],
    ['bike', 'Delivery no mesmo fluxo', 'Pedidos de entrega junto com os do salão.'],
    ['wallet', 'Caixa descomplicado', 'Vendas e recebimentos do dia sempre claros.'],
];

/**
 * Campo de formulario padrao. $mask: phone|cpf|cnpj; $validate: tipo de validacao no navegador.
 */
$field = static function (array $f) use ($e): string {
    $id = 'cadastro-' . $f['name'];
    $attrs = '';
    foreach ($f['attrs'] ?? [] as $key => $value) {
        $attrs .= ' ' . $key . '="' . $e((string) $value) . '"';
    }
    $hint = $f['hint'] ?? '';
    ob_start(); ?>
    <div class="field<?= isset($f['class']) ? ' ' . $f['class'] : '' ?>" data-field>
        <label for="<?= $id ?>"><?= $e($f['label']) ?><?php if (!empty($f['optional'])): ?> <span class="field-optional">(opcional)</span><?php endif; ?></label>
        <div class="field-control">
            <?= Icon::svg($f['icon'], 'field-icon') ?>
            <input id="<?= $id ?>" name="<?= $e($f['name']) ?>" aria-describedby="<?= $id ?>-msg"<?= empty($f['optional']) ? ' required' : '' ?><?= $attrs ?>>
            <?php if (($f['attrs']['type'] ?? '') === 'password'): ?>
                <button class="field-action" type="button" data-toggle-password aria-label="Mostrar senha" aria-pressed="false">
                    <?= Icon::svg('eye', 'icon-show') ?><?= Icon::svg('eye-off', 'icon-hide') ?>
                </button>
            <?php endif; ?>
        </div>
        <?php if (!empty($f['strength'])): ?>
            <div class="strength" data-strength aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <?php endif; ?>
        <p class="field-msg" id="<?= $id ?>-msg" aria-live="polite" data-hint="<?= $e($hint) ?>"><?= $e($hint) ?></p>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<section class="auth" aria-labelledby="register-title">
    <div class="shell auth-layout">
        <?php require __DIR__ . '/../partials/auth-aside.php'; ?>
        <div class="auth-card auth-card-wide" data-reveal>
            <h1 id="register-title">Crie sua conta.</h1>
            <p class="auth-lead">Uma experiência simples para colocar seu negócio no controle.</p>

            <?php if ($plano !== null): ?>
                <div class="plan-chosen" aria-label="Plano selecionado">
                    <span class="plan-chosen-icon"><?= Icon::svg('badge-check') ?></span>
                    <div class="plan-chosen-info">
                        <span>Plano selecionado</span>
                        <strong><?= $e((string) $plano['nome']) ?></strong>
                    </div>
                    <div class="plan-chosen-value">
                        <strong>R$ <?= number_format((float) $plano['valor'], 2, ',', '.') ?></strong>
                        <span><?= match ($plano['periodo']) {
                            'mensal' => 'por mês',
                            'semestral' => 'a cada 6 meses',
                            'anual' => 'por ano',
                            default => 'por período',
                        } ?></span>
                    </div>
                    <a class="plan-chosen-change" href="/#planos">Trocar plano</a>
                </div>
            <?php elseif ($planoInvalido): ?>
                <div class="notice notice-warning" role="status">
                    <?= Icon::svg('info') ?>
                    <div>
                        <strong>Plano não encontrado</strong>
                        <p>O plano selecionado não está disponível. <a href="/#planos">Escolha outro plano</a>.</p>
                    </div>
                </div>
            <?php else: ?>
                <p class="plan-hint"><?= Icon::svg('wallet', 'icon-sm') ?>Ainda não escolheu um plano? <a href="/#planos">Veja as opções disponíveis</a>.</p>
            <?php endif; ?>

            <div class="notice notice-info" role="status">
                <?= Icon::svg('info') ?>
                <div>
                    <strong>Cadastro em preparação</strong>
                    <p>Ainda não estamos recebendo inscrições. Você já pode testar o preenchimento, mas os dados não são enviados.</p>
                </div>
            </div>

            <form class="auth-form" method="post" novalidate data-preview-form aria-label="Prévia do formulário de cadastro">
                <?php if ($plano !== null): ?>
                    <input type="hidden" name="plano_id" value="<?= (int) $plano['id'] ?>">
                <?php endif; ?>

                <fieldset class="form-group">
                    <legend><span>1</span>Seus dados</legend>
                    <div class="field-row">
                        <?= $field(['name' => 'nome', 'label' => 'Nome', 'icon' => 'user', 'attrs' => ['type' => 'text', 'autocomplete' => 'given-name', 'placeholder' => 'Seu nome', 'maxlength' => 120, 'data-validate' => 'name']]) ?>
                        <?= $field(['name' => 'sobrenome', 'label' => 'Sobrenome', 'icon' => 'user', 'attrs' => ['type' => 'text', 'autocomplete' => 'family-name', 'placeholder' => 'Seu sobrenome', 'maxlength' => 150, 'data-validate' => 'name']]) ?>
                    </div>
                    <div class="field-row">
                        <?= $field(['name' => 'cpf', 'label' => 'CPF', 'icon' => 'id-card', 'hint' => 'Somente números. A pontuação aparece sozinha.', 'attrs' => ['type' => 'text', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => '000.000.000-00', 'maxlength' => 14, 'data-mask' => 'cpf', 'data-validate' => 'cpf']]) ?>
                        <?= $field(['name' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => 'phone', 'attrs' => ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel-national', 'placeholder' => '(85) 99999-9999', 'maxlength' => 15, 'data-mask' => 'phone', 'data-validate' => 'phone']]) ?>
                    </div>
                </fieldset>

                <fieldset class="form-group">
                    <legend><span>2</span>Seu restaurante</legend>
                    <div class="field-row">
                        <?= $field(['name' => 'nome_restaurante', 'label' => 'Nome do restaurante', 'icon' => 'store', 'attrs' => ['type' => 'text', 'autocomplete' => 'organization', 'placeholder' => 'Ex.: Cantina do Zé', 'maxlength' => 180, 'data-validate' => 'required']]) ?>
                        <?= $field(['name' => 'cnpj', 'label' => 'CNPJ do restaurante', 'icon' => 'file-text', 'hint' => 'Cada CNPJ pode ter apenas um restaurante.', 'attrs' => ['type' => 'text', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => '00.000.000/0000-00', 'maxlength' => 18, 'data-mask' => 'cnpj', 'data-validate' => 'cnpj', 'data-unique' => 'cnpj']]) ?>
                    </div>
                </fieldset>

                <fieldset class="form-group">
                    <legend><span>3</span>Seu acesso</legend>
                    <div class="field-row">
                        <?= $field(['name' => 'usuario', 'label' => 'Nome de usuário', 'icon' => 'at-sign', 'hint' => 'Letras minúsculas, números, ponto, hífen ou sublinhado.', 'attrs' => ['type' => 'text', 'autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'placeholder' => 'ex.: cantina.do.ze', 'maxlength' => 60, 'data-mask' => 'username', 'data-validate' => 'username', 'data-unique' => 'usuario']]) ?>
                        <?= $field(['name' => 'email', 'label' => 'E-mail', 'icon' => 'mail', 'attrs' => ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'placeholder' => 'seu@email.com', 'maxlength' => 190, 'data-validate' => 'email', 'data-unique' => 'email']]) ?>
                    </div>
                    <?= $field(['name' => 'senha', 'label' => 'Senha', 'icon' => 'lock', 'strength' => true, 'hint' => 'Use 8 ou mais caracteres, misturando letras e números.', 'attrs' => ['type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => 'Crie uma senha segura', 'minlength' => 8, 'data-validate' => 'password']]) ?>
                </fieldset>

                <label class="check">
                    <input type="checkbox" name="aceite_termos" required>
                    <span class="check-box" aria-hidden="true"><?= Icon::svg('check') ?></span>
                    <span>Li e concordo com os <a href="/termos-de-uso">Termos de Uso</a> e a <a href="/privacidade">Política de Privacidade</a>.</span>
                </label>

                <button class="btn btn-wine btn-lg btn-block" type="submit" disabled><?= Icon::svg('lock') ?>Cadastro disponível em breve</button>
            </form>
            <p class="auth-switch">Já possui uma conta? <a href="/login">Ir para o login <?= Icon::svg('arrow-right', 'icon-sm') ?></a></p>
        </div>
    </div>
</section>
