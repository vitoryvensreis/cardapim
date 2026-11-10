<section class="account-stage" aria-labelledby="register-title">
    <div class="account-card account-card-wide">
        <div class="account-overline">COMECE PELO SEU RESTAURANTE</div>
        <h1 id="register-title">Crie sua conta.</h1>
        <p class="account-lead">Uma experiência simples para colocar seu negócio no controle.</p>

        <?php if ($plano !== null): ?>
            <div class="account-plan" aria-label="Plano selecionado">
                <div>
                    <span>Plano selecionado</span>
                    <strong><?= htmlspecialchars((string) $plano['nome'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                </div>
                <div class="account-plan-value">
                    <strong>R$ <?= number_format((float) $plano['valor'], 2, ',', '.') ?></strong>
                    <span><?= match ($plano['periodo']) {
                        'mensal' => 'por mês',
                        'semestral' => 'a cada 6 meses',
                        'anual' => 'por ano',
                        default => 'por período',
                    } ?></span>
                </div>
                <a href="/#planos">Trocar plano</a>
            </div>
        <?php elseif ($planoInvalido): ?>
            <div class="account-notice account-notice-warning" role="status">
                <strong>Plano não encontrado</strong>
                <p>O plano selecionado não está disponível. <a href="/#planos">Escolha outro plano</a>.</p>
            </div>
        <?php else: ?>
            <p class="account-plan-hint">Ainda não escolheu um plano? <a href="/#planos">Veja as opções disponíveis</a>.</p>
        <?php endif; ?>

        <div class="account-notice" role="status">
            <strong>Cadastro em preparação</strong>
            <p>Ainda não estamos recebendo inscrições. O formulário abaixo é uma prévia e não envia seus dados.</p>
        </div>

        <form class="account-form" aria-label="Prévia do formulário de cadastro">
            <fieldset disabled>
                <?php if ($plano !== null): ?>
                    <input type="hidden" name="plano_id" value="<?= (int) $plano['id'] ?>">
                <?php endif; ?>
                <div class="account-field-row">
                    <div class="account-field">
                        <label for="cadastro-nome">Nome</label>
                        <input id="cadastro-nome" name="nome" type="text" autocomplete="given-name" placeholder="Seu nome">
                    </div>
                    <div class="account-field">
                        <label for="cadastro-sobrenome">Sobrenome</label>
                        <input id="cadastro-sobrenome" name="sobrenome" type="text" autocomplete="family-name" placeholder="Seu sobrenome">
                    </div>
                </div>
                <div class="account-field">
                    <label for="cadastro-email">E-mail</label>
                    <input id="cadastro-email" name="email" type="email" autocomplete="email" placeholder="seu@email.com">
                </div>
                <div class="account-field">
                    <label for="cadastro-whatsapp">WhatsApp</label>
                    <input id="cadastro-whatsapp" name="whatsapp" type="tel" autocomplete="tel" inputmode="tel" placeholder="(85) 99999-9999">
                </div>
                <div class="account-field">
                    <label for="cadastro-senha">Senha</label>
                    <input id="cadastro-senha" name="senha" type="password" autocomplete="new-password" placeholder="Crie uma senha segura">
                </div>
                <label class="account-agreement">
                    <input type="checkbox" name="aceite_termos">
                    <span>Li e concordo com os <a href="/termos-de-uso">Termos de Uso</a> e a <a href="/privacidade">Política de Privacidade</a>.</span>
                </label>
                <button class="account-submit" type="button" disabled>Cadastro disponível em breve</button>
            </fieldset>
        </form>
        <p class="account-switch">Já possui uma conta? <a href="/login">Ir para o login</a></p>
    </div>
</section>
