<?php
/**
 * Cadastro do restaurante. Espera: $csrf, $planos (publicos), $plano (escolhido ou do convite), $convite (codigo|null),
 * $planoInvalido, $pagamentoDisponivel, $testeDias, $valores, $erros.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\AssinaturaService;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$valor = static fn (string $c): string => (string) ($valores[$c] ?? '');
$asideTitle = 'Comece pelo seu restaurante. O resto a gente organiza.';
$asideItems = [
    ['clipboard-list', 'Mesas e comandas', 'Veja o salão inteiro e feche contas sem confusão.'],
    ['bike', 'Delivery no mesmo fluxo', 'Pedidos de entrega junto com os do salão.'],
    ['wallet', 'Caixa descomplicado', 'Vendas e recebimentos do dia sempre claros.'],
];
$preco = static function (array $p): array {
    if ((float) $p['valor'] <= 0) {
        return ['Grátis', $p['periodo'] === 'vitalicio' ? 'para sempre' : ''];
    }
    return ['R$ ' . number_format((float) $p['valor'], 2, ',', '.'), AssinaturaService::ROTULO_PERIODO[$p['periodo']] ?? ''];
};

/** Campo de formulario padrao, com o valor digitado e o erro vindo do servidor. */
$field = static function (array $f) use ($e, $valor, $erros): string {
    $id = 'cadastro-' . $f['name'];
    $erro = $erros[$f['name']] ?? null;
    $attrs = '';
    foreach ($f['attrs'] ?? [] as $key => $value) {
        $attrs .= ' ' . $key . '="' . $e((string) $value) . '"';
    }
    if (($f['attrs']['type'] ?? '') !== 'password') {
        $attrs .= ' value="' . $e($valor($f['name'])) . '"';
    }
    $hint = $f['hint'] ?? '';
    ob_start(); ?>
    <div class="field<?= isset($f['class']) ? ' ' . $f['class'] : '' ?><?= $erro !== null ? ' is-invalid' : '' ?>" data-field>
        <label for="<?= $id ?>"><?= $e($f['label']) ?></label>
        <div class="field-control">
            <?= Icon::svg($f['icon'], 'field-icon') ?>
            <input id="<?= $id ?>" name="<?= $e($f['name']) ?>" aria-describedby="<?= $id ?>-msg" required<?= $erro !== null ? ' aria-invalid="true"' : '' ?><?= $attrs ?>>
            <?php if (($f['attrs']['type'] ?? '') === 'password'): ?>
                <button class="field-action" type="button" data-toggle-password aria-label="Mostrar senha" aria-pressed="false">
                    <?= Icon::svg('eye', 'icon-show') ?><?= Icon::svg('eye-off', 'icon-hide') ?>
                </button>
            <?php endif; ?>
        </div>
        <?php if (!empty($f['strength'])): ?>
            <div class="strength" data-strength aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <?php endif; ?>
        <p class="field-msg" id="<?= $id ?>-msg" aria-live="polite" data-hint="<?= $e($hint) ?>"><?= $e($erro ?? $hint) ?></p>
    </div>
    <?php return (string) ob_get_clean();
};
$planoEscolhido = $valor('plano_id') !== '' ? $valor('plano_id') : ($plano !== null ? (string) $plano['id'] : '');
$temPago = array_filter($planos, static fn (array $p): bool => (float) $p['valor'] > 0) !== [];
?>
<section class="auth" aria-labelledby="register-title">
    <div class="shell auth-layout">
        <?php require __DIR__ . '/../partials/auth-aside.php'; ?>
        <div class="auth-card auth-card-wide" data-reveal>
            <h1 id="register-title">Crie sua conta.</h1>
            <p class="auth-lead">Em poucos minutos o seu restaurante está pronto para usar.</p>

            <?php if (isset($erros['geral'])): ?>
                <div class="notice notice-warning" role="alert"><?= Icon::svg('circle-x') ?><div><strong><?= $e($erros['geral']) ?></strong></div></div>
            <?php elseif ($erros !== []): ?>
                <div class="notice notice-warning" role="alert"><?= Icon::svg('circle-x') ?><div><strong>Confira os campos destacados.</strong></div></div>
            <?php endif; ?>

            <form class="auth-form" method="post" action="/cadastro" novalidate data-validate-form aria-label="Cadastro do restaurante">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

                <fieldset class="form-group" id="campo-plano_id">
                    <legend><span>1</span>Seu plano</legend>
                    <?php if ($convite !== null && $plano !== null):
                        [$valorPlano, $periodoPlano] = $preco($plano); ?>
                        <input type="hidden" name="convite" value="<?= $e($convite) ?>">
                        <div class="plan-chosen" aria-label="Plano do convite">
                            <span class="plan-chosen-icon"><?= Icon::svg('gift') ?></span>
                            <div class="plan-chosen-info"><span>Plano exclusivo do seu convite</span><strong><?= $e((string) $plano['nome']) ?></strong></div>
                            <div class="plan-chosen-value"><strong><?= $e($valorPlano) ?></strong><span><?= $e($periodoPlano) ?></span></div>
                        </div>
                    <?php elseif ($convite !== null): ?>
                        <div class="notice notice-warning" role="status"><?= Icon::svg('info') ?><div><strong>Este link de convite não vale mais.</strong><p>Peça um link novo para quem enviou ou <a href="/cadastro">escolha um plano</a>.</p></div></div>
                    <?php elseif ($planos === []): ?>
                        <div class="notice notice-warning" role="status"><?= Icon::svg('info') ?><div><strong>Nenhum plano disponível agora.</strong><p>Volte em breve.</p></div></div>
                    <?php else: ?>
                        <?php if ($planoInvalido): ?>
                            <p class="plan-hint"><?= Icon::svg('info', 'icon-sm') ?>O plano do link não está mais disponível. Escolha um abaixo.</p>
                        <?php endif; ?>
                        <div class="planos-cadastro" role="radiogroup" aria-label="Escolha o plano">
                            <?php foreach ($planos as $p):
                                [$valorPlano, $periodoPlano] = $preco($p);
                                $indisponivel = (float) $p['valor'] > 0 && !$pagamentoDisponivel; ?>
                                <label class="plano-cadastro<?= $indisponivel ? ' is-indisponivel' : '' ?>">
                                    <input type="radio" name="plano_id" value="<?= (int) $p['id'] ?>"<?= $planoEscolhido === (string) $p['id'] ? ' checked' : '' ?><?= $indisponivel ? ' disabled' : '' ?> required>
                                    <span class="plano-cadastro-nome"><?= $e((string) $p['nome']) ?></span>
                                    <span class="plano-cadastro-preco"><strong><?= $e($valorPlano) ?></strong> <?= $e($periodoPlano) ?></span>
                                    <?php if (!empty($p['descricao'])): ?><span class="plano-cadastro-desc"><?= $e((string) $p['descricao']) ?></span><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($temPago && !$pagamentoDisponivel): ?>
                            <p class="plan-hint"><?= Icon::svg('clock', 'icon-sm') ?>O pagamento online está em manutenção. Os planos pagos voltam em breve.</p>
                        <?php elseif ($temPago && $testeDias > 0): ?>
                            <p class="plan-hint"><?= Icon::svg('gift', 'icon-sm') ?>Planos pagos começam com <?= (int) $testeDias ?> dias grátis. A primeira cobrança vem só depois.</p>
                        <?php elseif ($temPago): ?>
                            <p class="plan-hint"><?= Icon::svg('shield-check', 'icon-sm') ?>Depois do cadastro, a fatura aparece no seu painel. Sem fidelidade.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (isset($erros['plano_id'])): ?><p class="field-msg cadastro-erro" role="alert"><?= $e($erros['plano_id']) ?></p><?php endif; ?>
                </fieldset>

                <fieldset class="form-group">
                    <legend><span>2</span>Seus dados</legend>
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
                    <legend><span>3</span>Seu restaurante</legend>
                    <div class="field-row">
                        <?= $field(['name' => 'nome_restaurante', 'label' => 'Nome do restaurante', 'icon' => 'store', 'hint' => 'Vira o endereço do seu cardápio.', 'attrs' => ['type' => 'text', 'autocomplete' => 'organization', 'placeholder' => 'Ex.: Cantina do Zé', 'maxlength' => 180, 'data-validate' => 'required']]) ?>
                        <?= $field(['name' => 'cnpj', 'label' => 'CNPJ do restaurante', 'icon' => 'file-text', 'hint' => 'Cada CNPJ pode ter apenas um restaurante.', 'attrs' => ['type' => 'text', 'inputmode' => 'numeric', 'autocomplete' => 'off', 'placeholder' => '00.000.000/0000-00', 'maxlength' => 18, 'data-mask' => 'cnpj', 'data-validate' => 'cnpj', 'data-unique' => 'cnpj']]) ?>
                    </div>
                </fieldset>

                <fieldset class="form-group">
                    <legend><span>4</span>Seu acesso</legend>
                    <div class="field-row">
                        <?= $field(['name' => 'usuario', 'label' => 'Nome de usuário', 'icon' => 'at-sign', 'hint' => 'Letras minúsculas, números, ponto, hífen ou sublinhado.', 'attrs' => ['type' => 'text', 'autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'placeholder' => 'ex.: cantina.do.ze', 'maxlength' => 60, 'data-mask' => 'username', 'data-validate' => 'username', 'data-unique' => 'usuario']]) ?>
                        <?= $field(['name' => 'email', 'label' => 'E-mail', 'icon' => 'mail', 'hint' => 'As faturas e avisos chegam aqui.', 'attrs' => ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'placeholder' => 'seu@email.com', 'maxlength' => 190, 'data-validate' => 'email', 'data-unique' => 'email']]) ?>
                    </div>
                    <?= $field(['name' => 'senha', 'label' => 'Senha', 'icon' => 'lock', 'strength' => true, 'hint' => 'Use 8 ou mais caracteres, misturando letras e números.', 'attrs' => ['type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => 'Crie uma senha segura', 'minlength' => 8, 'maxlength' => 128, 'data-validate' => 'password']]) ?>
                </fieldset>

                <label class="check<?= isset($erros['aceite_termos']) ? ' is-invalid' : '' ?>" id="campo-aceite_termos">
                    <input type="checkbox" name="aceite_termos" value="1" required<?= $valor('aceite_termos') !== '' ? ' checked' : '' ?>>
                    <span class="check-box" aria-hidden="true"><?= Icon::svg('check') ?></span>
                    <span>Li e concordo com os <a href="/termos-de-uso" target="_blank" rel="noopener">Termos de Uso</a> e a <a href="/privacidade" target="_blank" rel="noopener">Política de Privacidade</a>.</span>
                </label>
                <?php if (isset($erros['aceite_termos'])): ?><p class="field-msg cadastro-erro" role="alert"><?= $e($erros['aceite_termos']) ?></p><?php endif; ?>

                <button class="btn btn-wine btn-lg btn-block" type="submit" data-submit-label="Criando sua conta..."><?= Icon::svg('arrow-right') ?><span>Criar conta e continuar</span></button>
            </form>
            <p class="auth-switch">Já possui uma conta? <a href="/login">Ir para o login <?= Icon::svg('arrow-right', 'icon-sm') ?></a></p>
        </div>
    </div>
</section>
