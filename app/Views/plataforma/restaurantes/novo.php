<?php
/**
 * Cadastrar restaurante pelo painel da plataforma (restaurante + gerente + plano).
 * Espera: $valores, $erros, $planos (ativos), $asaasConectado, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Cadastro\CadastroRestaurante;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$val = static fn (string $c): string => (string) ($valores[$c] ?? '');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="/painel/restaurantes"><?= Icon::svg('arrow-left', 'icon-sm') ?>Restaurantes</a>
            <h1>Novo restaurante</h1>
            <p>Cria o restaurante, a conta do gerente, 5 mesas e a assinatura do plano. O gerente já pode entrar e montar o cardápio.</p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="/painel/restaurantes" novalidate data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-plano">
            <div class="bloco-cabecalho">
                <h2 id="titulo-plano"><?= Icon::svg('tags') ?>Plano</h2>
                <p>Pode ser qualquer plano ativo, inclusive os privados (só com convite).<?= $asaasConectado ? ' Planos pagos geram a cobrança no Asaas da plataforma.' : ' O Asaas da plataforma não está conectado: só planos grátis.' ?></p>
            </div>
            <?php if ($planos === []): ?>
                <p class="notice notice-warning"><?= Icon::svg('triangle-exclamation-point') ?><span>Nenhum plano ativo. <a href="/painel/planos/novo">Crie um plano</a> antes.</span></p>
            <?php else: ?>
                <fieldset class="opcoes-modo gestao-planos" id="campo-plano_id">
                    <legend class="sr-only">Plano do restaurante</legend>
                    <?php foreach ($planos as $pl):
                        $gratis = (float) $pl['valor'] <= 0;
                        $bloqueado = !$gratis && !$asaasConectado; ?>
                        <label class="opcao-modo<?= $bloqueado ? ' is-desligada' : '' ?>">
                            <input type="radio" name="plano_id" value="<?= (int) $pl['id'] ?>"<?= $val('plano_id') === (string) $pl['id'] ? ' checked' : '' ?><?= $bloqueado ? ' disabled' : '' ?> required>
                            <span>
                                <strong><?= $e((string) $pl['nome']) ?></strong>
                                <small><?= $gratis ? 'Grátis' . ($pl['periodo'] === 'vitalicio' ? ' para sempre' : '') : $e(Numero::reais((string) $pl['valor']) . ' ' . (AssinaturaService::ROTULO_PERIODO[$pl['periodo']] ?? '')) ?><?= (int) $pl['visibilidade'] === 1 ? '' : ' · privado' ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (isset($erros['plano_id'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['plano_id']) ?></p><?php endif; ?>
                </fieldset>
            <?php endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-restaurante">
            <div class="bloco-cabecalho">
                <h2 id="titulo-restaurante"><?= Icon::svg('store') ?>Restaurante</h2>
                <p>O endereço do cardápio é criado a partir do nome. Endereço completo e horários o gerente preenche no Perfil.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome_restaurante', 'rotulo' => 'Nome do restaurante', 'valor' => $val('nome_restaurante'), 'icone' => 'store', 'erro' => $erros['nome_restaurante'] ?? null,
                    'classe' => 'col-inteira', 'attrs' => ['maxlength' => 180, 'autocomplete' => 'off', 'data-validate' => 'required']]) ?>
                <?= Campo::texto(['nome' => 'telefone', 'rotulo' => 'Telefone do restaurante', 'valor' => $val('telefone'), 'icone' => 'phone', 'erro' => $erros['telefone'] ?? null,
                    'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'off', 'data-mask' => 'phone', 'data-validate' => 'phone', 'placeholder' => '(00) 00000-0000']]) ?>
                <?= Campo::texto(['nome' => 'cnpj', 'rotulo' => 'CNPJ', 'valor' => $val('cnpj'), 'icone' => 'id-card', 'erro' => $erros['cnpj'] ?? null, 'opcional' => true,
                    'dica' => 'Obrigatório para plano pago (o Asaas usa na cobrança).', 'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'data-mask' => 'cnpj', 'placeholder' => '00.000.000/0000-00']]) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-gerente">
            <div class="bloco-cabecalho">
                <h2 id="titulo-gerente"><?= Icon::svg('user') ?>Gerente</h2>
                <p>Quem vai administrar o restaurante. Passe o usuário e a senha para ele entrar.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome', 'valor' => $val('nome'), 'icone' => 'user', 'erro' => $erros['nome'] ?? null, 'attrs' => ['maxlength' => 120, 'autocomplete' => 'off', 'data-validate' => 'name']]) ?>
                <?= Campo::texto(['nome' => 'sobrenome', 'rotulo' => 'Sobrenome', 'valor' => $val('sobrenome'), 'icone' => 'user', 'erro' => $erros['sobrenome'] ?? null, 'attrs' => ['maxlength' => 150, 'autocomplete' => 'off', 'data-validate' => 'name']]) ?>
                <?= Campo::texto(['nome' => 'email', 'rotulo' => 'E-mail do gerente', 'tipo' => 'email', 'valor' => $val('email'), 'icone' => 'mail', 'erro' => $erros['email'] ?? null,
                    'classe' => 'col-inteira', 'dica' => 'Também é o e-mail de contato do restaurante.', 'attrs' => ['maxlength' => 190, 'autocomplete' => 'off', 'autocapitalize' => 'none', 'data-validate' => 'email', 'data-unique' => 'email']]) ?>
                <?= Campo::texto(['nome' => 'usuario', 'rotulo' => 'Usuário (para entrar)', 'valor' => $val('usuario'), 'icone' => 'at-sign', 'erro' => $erros['usuario'] ?? null,
                    'dica' => 'Sem espaços. Exemplo: maria.cantina', 'attrs' => ['maxlength' => 60, 'autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'data-mask' => 'username', 'data-validate' => 'username', 'data-unique' => 'usuario', 'data-usuario']]) ?>
                <?= Campo::texto(['nome' => 'senha', 'rotulo' => 'Senha', 'tipo' => 'password', 'icone' => 'lock', 'erro' => $erros['senha'] ?? null, 'senha' => true,
                    'dica' => 'Pelo menos ' . CadastroRestaurante::SENHA_MIN . ' caracteres.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => CadastroRestaurante::SENHA_MIN, 'maxlength' => 128, 'data-validate' => 'password', 'data-senha']]) ?>
                <div class="col-inteira senha-ajuda">
                    <button class="link-acao" type="button" data-gerar-senha><?= Icon::svg('sparkles', 'icon-sm') ?>Gerar uma senha fácil de passar</button>
                    <button class="link-acao" type="button" data-copiar-acesso hidden><?= Icon::svg('copy', 'icon-sm') ?>Copiar usuário e senha</button>
                    <span class="texto-suave" data-copiado role="status"></span>
                </div>
                <?= Campo::texto(['nome' => 'whatsapp', 'rotulo' => 'WhatsApp do gerente', 'valor' => $val('whatsapp'), 'icone' => 'phone', 'erro' => $erros['whatsapp'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'off', 'data-mask' => 'phone', 'placeholder' => '(00) 00000-0000']]) ?>
                <?= Campo::texto(['nome' => 'cpf', 'rotulo' => 'CPF do gerente', 'valor' => $val('cpf'), 'icone' => 'id-card', 'erro' => $erros['cpf'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'data-mask' => 'cpf', 'placeholder' => '000.000.000-00']]) ?>
            </div>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="/painel/restaurantes">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Cadastrando..."<?= $planos === [] ? ' disabled' : '' ?>><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar restaurante</button>
        </div>
    </form>
</div>
