<?php
/**
 * Criar/editar conta pela administracao. Espera: $conta (null = nova), $valores, $erros, $restaurantes, $souEu, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$val = static fn (string $c): string => (string) ($valores[$c] ?? '');
$nova = $conta === null;
$acaoForm = $nova ? '/painel/usuarios' : '/painel/usuarios/' . (int) $conta['id'];
$temAcesso = !$nova && (int) $conta['ativo'] === 1;
$funcoes = [
    'gerente' => ['Gerente', 'Administra o restaurante: cardápio, equipe, financeiro, caixa.', 'store'],
    'caixa' => ['Caixa', 'Abre e fecha o caixa, recebe pagamentos, mesas e delivery.', 'monitor'],
    'garcom' => ['Garçom', 'Lança pedidos nas mesas e fecha contas pelo celular.', 'armchair'],
    'motoqueiro' => ['Entregador', 'Vê as próprias entregas e compartilha a localização.', 'bike'],
    'adm_master' => ['Administrador da plataforma', 'Acesso total: restaurantes, planos, usuários e integrações.', 'shield-check'],
];
$quando = static fn (?string $d): string => $d ? date('d/m/Y H:i', (int) strtotime($d)) : 'nunca';
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="/painel/usuarios"><?= Icon::svg('arrow-left', 'icon-sm') ?>Usuários</a>
            <h1><?= $nova ? 'Nova conta' : $e($conta['nome'] . ' ' . $conta['sobrenome']) ?></h1>
            <p><?php if ($nova): ?>Crie o acesso e passe o usuário e a senha para a pessoa.<?php else: ?>
                <?= $e(Autorizacao::rotuloCargo((string) $conta['cargo'])) ?><?= $conta['restaurante_nome'] !== null ? ' em <a href="/painel/restaurantes/' . (int) $conta['id_restaurante'] . '">' . $e((string) $conta['restaurante_nome']) . '</a>' : '' ?>
                · último acesso <?= $e($quando($conta['ultimo_acesso'])) ?><?= $souEu ? ' · esta é a sua conta' : '' ?><?php endif; ?></p>
        </div>
        <?php if (!$nova && !$temAcesso): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Acesso bloqueado</span><?php endif; ?>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="<?= $e($acaoForm) ?>" novalidate data-validate-form data-conta-adm>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <section class="bloco" aria-labelledby="titulo-funcao">
            <div class="bloco-cabecalho">
                <h2 id="titulo-funcao"><?= Icon::svg('id-card') ?>Função e restaurante</h2>
                <p>A função define o que a pessoa vê. Toda função, menos administrador, pertence a um restaurante.</p>
            </div>
            <fieldset class="opcoes-modo gestao-funcoes" id="campo-cargo">
                <legend class="sr-only">Função</legend>
                <?php foreach ($funcoes as $cargoChave => [$rotuloFuncao, $descFuncao, $iconeFuncao]): ?>
                    <label class="opcao-modo">
                        <input type="radio" name="cargo" value="<?= $cargoChave ?>"<?= $val('cargo') === $cargoChave ? ' checked' : '' ?><?= $souEu && $val('cargo') !== $cargoChave ? ' disabled' : '' ?> required data-cargo>
                        <span><strong><?= Icon::svg($iconeFuncao, 'icon-sm') ?> <?= $e($rotuloFuncao) ?></strong><small><?= $e($descFuncao) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if (isset($erros['cargo'])): ?><p class="field-msg ficha-erro" role="alert"><?= $e($erros['cargo']) ?></p><?php endif; ?>
            </fieldset>
            <div data-campo-restaurante<?= $val('cargo') === Autorizacao::CARGO_MASTER ? ' hidden' : '' ?>>
                <?= Campo::selecao(['nome' => 'id_restaurante', 'rotulo' => 'Restaurante', 'valor' => $val('id_restaurante'), 'icone' => 'store', 'erro' => $erros['id_restaurante'] ?? null, 'vazio' => 'Escolha o restaurante', 'opcional' => true],
                    array_column(array_map(static fn (array $r): array => ['id' => $r['id'], 'rotulo' => $r['nome'] . ' (/' . $r['slug'] . ')'], $restaurantes), 'rotulo', 'id')) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-pessoa">
            <div class="bloco-cabecalho"><h2 id="titulo-pessoa"><?= Icon::svg('user') ?>Quem é</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome', 'valor' => $val('nome'), 'icone' => 'user', 'erro' => $erros['nome'] ?? null, 'attrs' => ['maxlength' => 120, 'autocomplete' => 'off', 'data-validate' => 'name']]) ?>
                <?= Campo::texto(['nome' => 'sobrenome', 'rotulo' => 'Sobrenome', 'valor' => $val('sobrenome'), 'icone' => 'user', 'erro' => $erros['sobrenome'] ?? null, 'attrs' => ['maxlength' => 150, 'autocomplete' => 'off', 'data-validate' => 'name']]) ?>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-acesso">
            <div class="bloco-cabecalho">
                <h2 id="titulo-acesso"><?= Icon::svg('key-round') ?>Acesso</h2>
                <p>Entra pelo login com o usuário (ou o e-mail)<?= $nova ? ' e a senha' : '' ?>.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'usuario', 'rotulo' => 'Usuário (para entrar)', 'valor' => $val('usuario'), 'icone' => 'at-sign', 'erro' => $erros['usuario'] ?? null,
                    'dica' => 'Sem espaços. Exemplo: joao.caixa', 'classe' => $nova ? '' : 'col-inteira',
                    'attrs' => ['maxlength' => 60, 'autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'data-mask' => 'username', 'data-validate' => 'username', 'data-usuario'] + ($nova ? ['data-unique' => 'usuario'] : [])]) ?>
                <?php if ($nova): ?>
                    <?= Campo::texto(['nome' => 'senha', 'rotulo' => 'Senha', 'tipo' => 'password', 'icone' => 'lock', 'erro' => $erros['senha'] ?? null, 'senha' => true,
                        'dica' => 'Pelo menos ' . EquipeService::SENHA_MIN . ' caracteres.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => EquipeService::SENHA_MIN, 'maxlength' => 128, 'data-validate' => 'password', 'data-senha']]) ?>
                    <div class="col-inteira senha-ajuda">
                        <button class="link-acao" type="button" data-gerar-senha><?= Icon::svg('sparkles', 'icon-sm') ?>Gerar uma senha fácil de passar</button>
                        <button class="link-acao" type="button" data-copiar-acesso hidden><?= Icon::svg('copy', 'icon-sm') ?>Copiar usuário e senha</button>
                        <span class="texto-suave" data-copiado role="status"></span>
                    </div>
                <?php endif; ?>
                <?= Campo::texto(['nome' => 'email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'valor' => $val('email'), 'icone' => 'mail', 'erro' => $erros['email'] ?? null, 'opcional' => true,
                    'classe' => 'col-inteira', 'dica' => 'Obrigatório para administrador e gerente (códigos e avisos). Para a equipe é opcional.', 'attrs' => ['maxlength' => 190, 'autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
                <?= Campo::texto(['nome' => 'whatsapp', 'rotulo' => 'WhatsApp', 'valor' => $val('whatsapp'), 'icone' => 'phone', 'erro' => $erros['whatsapp'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'tel', 'autocomplete' => 'off', 'data-mask' => 'phone', 'placeholder' => '(00) 00000-0000']]) ?>
                <?= Campo::texto(['nome' => 'cpf', 'rotulo' => 'CPF', 'valor' => $val('cpf'), 'icone' => 'id-card', 'erro' => $erros['cpf'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'data-mask' => 'cpf', 'placeholder' => '000.000.000-00']]) ?>
            </div>
        </section>

        <div class="form-acoes">
            <a class="btn btn-outline" href="/painel/usuarios">Cancelar</a>
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg($nova ? 'user-plus' : 'save', 'icon-sm') ?><?= $nova ? 'Criar conta' : 'Salvar alterações' ?></button>
        </div>
    </form>

    <?php if (!$nova): ?>
        <section class="bloco" id="senha" aria-labelledby="titulo-senha">
            <div class="bloco-cabecalho">
                <h2 id="titulo-senha"><?= Icon::svg('key-round') ?>Trocar a senha</h2>
                <p>Crie uma nova senha e passe para a pessoa. Ela é desconectada dos aparelhos onde ficou conectada.</p>
            </div>
            <form method="post" action="/painel/usuarios/<?= (int) $conta['id'] ?>/senha" novalidate data-validate-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" value="<?= $e((string) $conta['usuario']) ?>" data-usuario>
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

        <?php if ($conta['dois_fatores'] !== 'desativado'): ?>
            <section class="bloco" aria-labelledby="titulo-2fa">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-2fa"><?= Icon::svg('shield-off') ?>Verificação em duas etapas</h2>
                    <p>Ligada (<?= $conta['dois_fatores'] === 'app' ? 'aplicativo autenticador' : 'código por e-mail' ?>). Desligue só se a pessoa perdeu o celular e os códigos de recuperação.</p>
                </div>
                <form method="post" action="/painel/usuarios/<?= (int) $conta['id'] ?>/dois-fatores"
                      data-confirm="A pessoa passa a entrar só com a senha até ligar de novo em Minha conta." data-confirm-titulo="Desligar a verificação em duas etapas?" data-confirm-acao="Desligar">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('shield-off', 'icon-sm') ?>Desligar verificação em duas etapas</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$souEu): ?>
            <section class="bloco" aria-labelledby="titulo-bloqueio">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-bloqueio"><?= Icon::svg($temAcesso ? 'lock' : 'rotate-ccw') ?><?= $temAcesso ? 'Bloquear acesso' : 'Liberar acesso' ?></h2>
                    <p><?= $temAcesso ? 'A pessoa não consegue mais entrar e é desconectada na hora. Nada é apagado.' : 'A conta está bloqueada. Libere para a pessoa voltar a entrar.' ?></p>
                </div>
                <form method="post" action="/painel/usuarios/<?= (int) $conta['id'] ?>/acesso"
                      <?= $temAcesso ? 'data-confirm="' . $e($conta['nome'] . ' não vai conseguir entrar até você liberar de novo.') . '" data-confirm-titulo="Bloquear o acesso?" data-confirm-acao="Bloquear acesso"' : '' ?>>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn <?= $temAcesso ? 'btn-outline' : 'btn-wine' ?> btn-sm" type="submit"><?= Icon::svg($temAcesso ? 'lock' : 'rotate-ccw', 'icon-sm') ?><?= $temAcesso ? 'Bloquear acesso' : 'Liberar acesso' ?></button>
                </form>
            </section>

            <section class="bloco bloco-perigo" aria-labelledby="titulo-remover">
                <div class="bloco-cabecalho">
                    <h2 id="titulo-remover"><?= Icon::svg('user-x') ?>Excluir conta</h2>
                    <p>A pessoa perde o acesso para sempre e os dados pessoais são apagados. O histórico do que ela fez continua guardado.</p>
                </div>
                <form method="post" action="/painel/usuarios/<?= (int) $conta['id'] ?>/excluir"
                      data-confirm="<?= $e($conta['nome'] . ' perde o acesso para sempre. Isso não pode ser desfeito.') ?>"
                      data-confirm-titulo="Excluir a conta de <?= $e((string) $conta['nome']) ?>?" data-confirm-acao="Excluir conta" data-confirm-perigo="1">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-perigo btn-sm" type="submit"><?= Icon::svg('user-x', 'icon-sm') ?>Excluir conta</button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
