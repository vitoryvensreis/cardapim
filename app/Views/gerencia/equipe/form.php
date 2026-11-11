<?php
/**
 * Cadastro/edicao de pessoa da equipe. Espera: $pessoa (null = nova), $valores, $erros, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $campo): string => (string) ($valores[$campo] ?? '');
$funcoes = require __DIR__ . '/_funcoes.php';
$nova = $pessoa === null;
$acao = $nova ? $base . '/equipe' : $base . '/equipe/' . (int) $pessoa['id'];
$estaAtivo = !$nova && (int) $pessoa['ativo'] === 1;
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/equipe') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Equipe</a>
            <h1><?= $nova ? 'Adicionar pessoa' : $e($pessoa['nome'] . ' ' . $pessoa['sobrenome']) ?></h1>
            <p><?= $nova ? 'Crie o acesso. Depois é só passar o usuário e a senha para a pessoa.' : 'Altere os dados, troque a senha ou bloqueie o acesso.' ?></p>
        </div>
        <?php if (!$nova && !$estaAtivo): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Acesso bloqueado</span><?php endif; ?>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($acao) ?>" novalidate data-validate-form data-equipe-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-funcao">
            <div class="bloco-cabecalho">
                <h2 id="titulo-funcao"><?= Icon::svg('id-card') ?>Função</h2>
                <p>A função define o que a pessoa consegue ver e fazer no sistema.</p>
            </div>
            <fieldset class="opcoes-modo opcoes-funcao" id="campo-cargo">
                <legend class="sr-only">Função da pessoa</legend>
                <?php foreach ($funcoes as $cargo => [$rotulo, , $icone, $descricao]): ?>
                    <label class="opcao-modo">
                        <input type="radio" name="cargo" value="<?= $cargo ?>"<?= $v('cargo') === $cargo ? ' checked' : '' ?> required>
                        <span><strong><?= Icon::svg($icone, 'icon-sm') ?> <?= $e($rotulo) ?></strong><small><?= $e($descricao) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if (isset($erros['cargo'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['cargo']) ?></p><?php endif; ?>
            </fieldset>
        </section>

        <section class="bloco" aria-labelledby="titulo-pessoa">
            <div class="bloco-cabecalho"><h2 id="titulo-pessoa"><?= Icon::svg('user') ?>Quem é</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome', 'valor' => $v('nome'), 'icone' => 'user', 'erro' => $erros['nome'] ?? null,
                    'attrs' => ['maxlength' => 120, 'autocomplete' => 'off', 'data-validate' => 'name']]) ?>
                <?= Campo::texto(['nome' => 'sobrenome', 'rotulo' => 'Sobrenome', 'valor' => $v('sobrenome'), 'icone' => 'user', 'erro' => $erros['sobrenome'] ?? null,
                    'attrs' => ['maxlength' => 150, 'autocomplete' => 'off', 'data-validate' => 'name']]) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-acesso">
            <div class="bloco-cabecalho">
                <h2 id="titulo-acesso"><?= Icon::svg('key-round') ?>Acesso ao sistema</h2>
                <p>A pessoa entra pela tela de login do <?= marca_html() ?> com o usuário<?= $nova ? ' e a senha' : '' ?> abaixo.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'usuario', 'rotulo' => 'Usuário (para entrar)', 'valor' => $v('usuario'), 'icone' => 'at-sign', 'erro' => $erros['usuario'] ?? null,
                    'dica' => 'Sem espaços. Exemplo: joao.caixa', 'classe' => $nova ? '' : 'col-inteira',
                    'attrs' => ['maxlength' => 60, 'autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'data-mask' => 'username', 'data-validate' => 'username', 'data-usuario']
                        + ($nova ? ['data-unique' => 'usuario'] : [])]) ?>
                <?php if ($nova): ?>
                    <?= Campo::texto(['nome' => 'senha', 'rotulo' => 'Senha', 'tipo' => 'password', 'icone' => 'lock', 'erro' => $erros['senha'] ?? null, 'senha' => true,
                        'dica' => 'Pelo menos ' . EquipeService::SENHA_MIN . ' caracteres.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => EquipeService::SENHA_MIN, 'maxlength' => 128, 'data-validate' => 'password', 'data-senha']]) ?>
                    <div class="col-inteira senha-ajuda">
                        <button class="link-acao" type="button" data-gerar-senha><?= Icon::svg('sparkles', 'icon-sm') ?>Gerar uma senha fácil de passar</button>
                        <button class="link-acao" type="button" data-copiar-acesso hidden><?= Icon::svg('copy', 'icon-sm') ?>Copiar usuário e senha</button>
                        <span class="texto-suave" data-copiado role="status"></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-contato">
            <div class="bloco-cabecalho">
                <h2 id="titulo-contato"><?= Icon::svg('phone') ?>Contato e documento</h2>
                <p>Tudo opcional. O WhatsApp ajuda a falar com a pessoa e, no caso de entregadores, a avisar sobre entregas.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'whatsapp', 'rotulo' => 'WhatsApp', 'valor' => $v('whatsapp'), 'icone' => 'phone', 'erro' => $erros['whatsapp'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'off', 'data-mask' => 'phone', 'placeholder' => '(00) 00000-0000']]) ?>
                <?= Campo::texto(['nome' => 'cpf', 'rotulo' => 'CPF', 'valor' => $v('cpf'), 'icone' => 'id-card', 'erro' => $erros['cpf'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'data-mask' => 'cpf', 'placeholder' => '000.000.000-00']]) ?>
                <?= Campo::texto(['nome' => 'email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'valor' => $v('email'), 'icone' => 'mail', 'erro' => $erros['email'] ?? null, 'opcional' => true,
                    'classe' => 'col-inteira', 'dica' => 'Se a pessoa tiver, ela também pode entrar com o e-mail.', 'attrs' => ['maxlength' => 190, 'autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
            </div>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="<?= $e($base . '/equipe') ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg($nova ? 'user-plus' : 'save', 'icon-sm') ?><?= $nova ? 'Criar acesso' : 'Salvar alterações' ?></button>
        </div>
    </form>

    <?php if (!$nova): ?>
        <section class="bloco" id="senha" aria-labelledby="titulo-senha">
            <div class="bloco-cabecalho">
                <h2 id="titulo-senha"><?= Icon::svg('key-round') ?>Trocar a senha</h2>
                <p>Esqueceu a senha? Crie uma nova aqui e passe para a pessoa. Ela é desconectada dos aparelhos onde ficou conectada.</p>
            </div>
            <form method="post" action="<?= $e($base . '/equipe/' . (int) $pessoa['id'] . '/senha') ?>" novalidate data-validate-form data-senha-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" value="<?= $e((string) $pessoa['usuario']) ?>" data-usuario>
                <?= Campo::texto(['nome' => 'senha', 'rotulo' => 'Nova senha', 'tipo' => 'password', 'icone' => 'lock', 'senha' => true, 'id' => 'campo-nova-senha',
                    'dica' => 'Pelo menos ' . EquipeService::SENHA_MIN . ' caracteres.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => EquipeService::SENHA_MIN, 'maxlength' => 128, 'data-validate' => 'password', 'data-senha']]) ?>
                <div class="senha-ajuda">
                    <button class="link-acao" type="button" data-gerar-senha><?= Icon::svg('sparkles', 'icon-sm') ?>Gerar uma senha fácil de passar</button>
                    <button class="link-acao" type="button" data-copiar-acesso hidden><?= Icon::svg('copy', 'icon-sm') ?>Copiar usuário e senha</button>
                    <span class="texto-suave" data-copiado role="status"></span>
                </div>
                <div class="bloco-rodape">
                    <button class="btn btn-wine btn-sm" type="submit" data-submit-label="Trocando..."><?= Icon::svg('key-round', 'icon-sm') ?>Trocar senha</button>
                </div>
            </form>
        </section>

        <section class="bloco" aria-labelledby="titulo-bloqueio">
            <div class="bloco-cabecalho">
                <h2 id="titulo-bloqueio"><?= Icon::svg($estaAtivo ? 'lock' : 'rotate-ccw') ?><?= $estaAtivo ? 'Bloquear acesso' : 'Liberar acesso' ?></h2>
                <p><?= $estaAtivo ? 'Use quando a pessoa sair de férias ou estiver afastada. Nada é apagado e você pode liberar de novo quando quiser.' : 'A pessoa está bloqueada e não consegue entrar. Libere para ela voltar a usar o sistema.' ?></p>
            </div>
            <form method="post" action="<?= $e($base . '/equipe/' . (int) $pessoa['id'] . '/acesso') ?>"
                  <?= $estaAtivo ? 'data-confirm="' . $e($pessoa['nome'] . ' não vai conseguir entrar até você liberar de novo.') . '" data-confirm-titulo="Bloquear o acesso?" data-confirm-acao="Bloquear acesso"' : '' ?>>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn <?= $estaAtivo ? 'btn-outline' : 'btn-wine' ?> btn-sm" type="submit"><?= Icon::svg($estaAtivo ? 'lock' : 'rotate-ccw', 'icon-sm') ?><?= $estaAtivo ? 'Bloquear acesso' : 'Liberar acesso' ?></button>
            </form>
        </section>

        <section class="bloco bloco-perigo" aria-labelledby="titulo-remover">
            <div class="bloco-cabecalho">
                <h2 id="titulo-remover"><?= Icon::svg('user-x') ?>Remover da equipe</h2>
                <p>A pessoa perde o acesso para sempre e os dados pessoais dela são apagados. O histórico do que ela fez continua guardado.</p>
            </div>
            <form method="post" action="<?= $e($base . '/equipe/' . (int) $pessoa['id'] . '/excluir') ?>"
                  data-confirm="<?= $e($pessoa['nome'] . ' perde o acesso para sempre. Isso não pode ser desfeito.') ?>"
                  data-confirm-titulo="Remover <?= $e((string) $pessoa['nome']) ?> da equipe?" data-confirm-acao="Remover da equipe" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-perigo btn-sm" type="submit"><?= Icon::svg('user-x', 'icon-sm') ?>Remover da equipe</button>
            </form>
        </section>
    <?php endif; ?>
</div>
