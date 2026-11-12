<?php
/**
 * Minha conta (gerente e administrador). Espera: $conta, $dados, $erros (por formulario), $codigosRestantes, $aparelhos,
 * $seletorAtual, $acessos, $appDisponivel, $ajudaLogin, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\DoisFatores;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$d = static fn (string $campo): string => (string) ($dados[$campo] ?? '');
$errosDados = $erros['dados'] ?? [];
$errosSenha = $erros['senha'] ?? [];
$metodo = (string) $conta['dois_fatores'];
$ligada = $metodo !== DoisFatores::DESATIVADO;
$quando = static function (?string $data): string {
    $t = $data ? strtotime($data) : false;
    if ($t === false) {
        return '-';
    }
    return date('Y-m-d', $t) === date('Y-m-d') ? 'Hoje, ' . date('H:i', $t) : date('d/m/Y H:i', $t);
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1>Minha conta</h1>
            <p>Seus dados de acesso e a segurança da sua conta.</p>
        </div>
        <span class="etiqueta <?= $ligada ? 'etiqueta-ok' : 'etiqueta-atencao' ?>"><?= Icon::svg($ligada ? 'shield-check' : 'shield-off') ?><?= $ligada ? 'Conta protegida' : 'Proteção extra desligada' ?></span>
    </header>

    <form method="post" action="<?= $e($base . '/minha-conta/dados') ?>" novalidate data-validate-form id="dados">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?= Campo::resumoErros($errosDados) ?>
        <section class="bloco" aria-labelledby="titulo-dados">
            <div class="bloco-cabecalho"><h2 id="titulo-dados"><?= Icon::svg('user') ?>Seus dados</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome', 'valor' => $d('nome'), 'icone' => 'user', 'erro' => $errosDados['nome'] ?? null,
                    'attrs' => ['maxlength' => 120, 'autocomplete' => 'given-name', 'data-validate' => 'name']]) ?>
                <?= Campo::texto(['nome' => 'sobrenome', 'rotulo' => 'Sobrenome', 'valor' => $d('sobrenome'), 'icone' => 'user', 'erro' => $errosDados['sobrenome'] ?? null,
                    'attrs' => ['maxlength' => 150, 'autocomplete' => 'family-name', 'data-validate' => 'name']]) ?>
                <?= Campo::texto(['nome' => 'whatsapp', 'rotulo' => 'Telefone / WhatsApp', 'valor' => $d('whatsapp'), 'icone' => 'phone', 'erro' => $errosDados['whatsapp'] ?? null, 'opcional' => true,
                    'classe' => 'col-inteira', 'dica' => 'Usamos para falar com você sobre a sua conta.', 'attrs' => ['inputmode' => 'tel', 'data-mask' => 'phone', 'autocomplete' => 'tel', 'placeholder' => '(00) 00000-0000']]) ?>
            </div>
            <dl class="dados-fixos">
                <div><dt>Usuário</dt><dd><?= $e((string) $conta['usuario']) ?></dd></div>
                <div><dt>E-mail</dt><dd><?= $e((string) $conta['email']) ?></dd></div>
            </dl>
            <p class="texto-suave"><?= $e((string) ($ajudaLogin ?? 'Para trocar o usuário ou o e-mail, fale com o suporte.')) ?></p>
            <div class="bloco-rodape">
                <button class="btn btn-primary btn-sm" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar meus dados</button>
            </div>
        </section>
    </form>

    <form method="post" action="<?= $e($base . '/minha-conta/senha') ?>" novalidate data-validate-form id="senha">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="usuario" value="<?= $e((string) $conta['usuario']) ?>" autocomplete="username">
        <section class="bloco" aria-labelledby="titulo-senha">
            <div class="bloco-cabecalho">
                <h2 id="titulo-senha"><?= Icon::svg('lock') ?>Trocar senha</h2>
                <p>Depois de trocar, os outros aparelhos onde você ficou conectado vão pedir a nova senha.</p>
            </div>
            <?= Campo::texto(['nome' => 'senha_atual', 'rotulo' => 'Senha atual', 'tipo' => 'password', 'icone' => 'lock', 'erro' => $errosSenha['senha_atual'] ?? null,
                'attrs' => ['autocomplete' => 'current-password', 'maxlength' => 1024, 'data-validate' => 'required']]) ?>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'senha_nova', 'rotulo' => 'Nova senha', 'tipo' => 'password', 'icone' => 'key-round', 'erro' => $errosSenha['senha_nova'] ?? null, 'senha' => true,
                    'dica' => 'Pelo menos ' . EquipeService::SENHA_MIN . ' caracteres, misturando letras e números.', 'attrs' => ['autocomplete' => 'new-password', 'minlength' => EquipeService::SENHA_MIN, 'maxlength' => 128, 'data-validate' => 'password']]) ?>
                <?= Campo::texto(['nome' => 'senha_confirmacao', 'rotulo' => 'Repita a nova senha', 'tipo' => 'password', 'icone' => 'key-round', 'erro' => $errosSenha['senha_confirmacao'] ?? null,
                    'attrs' => ['autocomplete' => 'new-password', 'maxlength' => 128, 'data-validate' => 'required']]) ?>
            </div>
            <div class="bloco-rodape">
                <button class="btn btn-wine btn-sm" type="submit" data-submit-label="Trocando..."><?= Icon::svg('key-round', 'icon-sm') ?>Trocar senha</button>
            </div>
        </section>
    </form>

    <section class="bloco" id="duas-etapas" aria-labelledby="titulo-2fa">
        <div class="bloco-cabecalho">
            <h2 id="titulo-2fa"><?= Icon::svg('shield-check') ?>Verificação em duas etapas</h2>
            <p>Além da senha, pedimos um código no login. Mesmo que alguém descubra sua senha, não consegue entrar.</p>
        </div>
        <?php if (!$ligada): ?>
            <div class="opcoes-2fa">
                <article class="opcao-2fa">
                    <span class="lista-icone" aria-hidden="true"><?= Icon::svg('smartphone', 'icon-sm') ?></span>
                    <div>
                        <h3>Aplicativo autenticador <span class="etiqueta etiqueta-marca">Recomendado</span></h3>
                        <p>Use o Google Authenticator (ou outro parecido) no celular. Funciona mesmo sem internet.</p>
                    </div>
                    <?php if ($appDisponivel): ?>
                        <a class="btn btn-primary btn-sm" href="<?= $e($base . '/minha-conta/dois-fatores/app') ?>"><?= Icon::svg('qr-code', 'icon-sm') ?>Configurar</a>
                    <?php else: ?>
                        <span class="texto-suave">Indisponível no momento</span>
                    <?php endif; ?>
                </article>
                <article class="opcao-2fa">
                    <span class="lista-icone" aria-hidden="true"><?= Icon::svg('mail-check', 'icon-sm') ?></span>
                    <div>
                        <h3>Código por e-mail</h3>
                        <p>A cada login, enviamos um código para <?= $e(DoisFatores::emailMascarado((string) $conta['email'])) ?>.</p>
                    </div>
                    <form method="post" action="<?= $e($base . '/minha-conta/dois-fatores/email') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('mail', 'icon-sm') ?>Enviar código</button>
                    </form>
                </article>
            </div>
        <?php else: ?>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Situação</span><strong><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Ligada</span></strong></div>
                <div class="resumo-linha"><span>Método</span><strong><?= $e(DoisFatores::rotulo($metodo)) ?></strong></div>
                <div class="resumo-linha"><span>Ligada em</span><strong><?= $e($quando($conta['dois_fatores_ativado_em'] ?? null)) ?></strong></div>
                <div class="resumo-linha"><span>Códigos de recuperação</span><strong class="<?= $codigosRestantes <= 2 ? 'texto-alerta' : '' ?>"><?= (int) $codigosRestantes ?> de 10 sem usar</strong></div>
            </div>
            <?php if ($codigosRestantes <= 2): ?>
                <p class="texto-alerta plano-aviso">Restam poucos códigos de recuperação. Gere novos e guarde em lugar seguro.</p>
            <?php endif; ?>
            <div class="acoes-2fa">
                <details class="acao-senha">
                    <summary class="btn btn-outline btn-sm"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Gerar novos códigos de recuperação</summary>
                    <form method="post" action="<?= $e($base . '/minha-conta/codigos') ?>" class="linha-form">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label class="sr-only" for="senha-codigos">Sua senha</label>
                        <input class="campo-simples" id="senha-codigos" type="password" name="senha" required autocomplete="current-password" placeholder="Digite sua senha para confirmar">
                        <button class="btn btn-wine btn-sm" type="submit">Gerar códigos</button>
                    </form>
                </details>
                <details class="acao-senha">
                    <summary class="btn btn-outline btn-sm link-perigo"><?= Icon::svg('shield-off', 'icon-sm') ?>Desligar verificação</summary>
                    <form method="post" action="<?= $e($base . '/minha-conta/dois-fatores/desativar') ?>" class="linha-form">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label class="sr-only" for="senha-desligar">Sua senha</label>
                        <input class="campo-simples" id="senha-desligar" type="password" name="senha" required autocomplete="current-password" placeholder="Digite sua senha para confirmar">
                        <button class="btn btn-perigo btn-sm" type="submit">Desligar</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>
    </section>

    <section class="bloco" id="aparelhos" aria-labelledby="titulo-aparelhos">
        <div class="bloco-cabecalho">
            <h2 id="titulo-aparelhos"><?= Icon::svg('laptop') ?>Aparelhos conectados</h2>
            <p>Onde você marcou "Manter conectado". Não reconhece algum? Desconecte e troque a senha.</p>
        </div>
        <?php if ($aparelhos === []): ?>
            <div class="lista-vazia"><?= Icon::svg('laptop') ?><strong>Nenhum aparelho com "manter conectado"</strong><p>Quando você marcar essa opção no login, o aparelho aparece aqui.</p></div>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($aparelhos as $a): $atual = $seletorAtual !== null && hash_equals((string) $a['seletor'], $seletorAtual); ?>
                    <li class="lista-item lista-item-simples">
                        <span class="lista-principal">
                            <span class="lista-icone" aria-hidden="true"><?= Icon::svg(str_contains(mb_strtolower((string) $a['navegador']), 'android') || str_contains(mb_strtolower((string) $a['navegador']), 'iphone') ? 'smartphone' : 'laptop', 'icon-sm') ?></span>
                            <span class="lista-titulo">
                                <strong><?= $e((string) ($a['navegador'] ?: 'Aparelho sem identificação')) ?><?php if ($atual): ?> <span class="etiqueta etiqueta-ok">Este aparelho</span><?php endif; ?></strong>
                                <small>Último uso: <?= $e($quando($a['ultimo_uso_em'] ?: $a['criado_em'])) ?> · Conectado desde <?= $e($quando((string) $a['criado_em'])) ?></small>
                            </span>
                        </span>
                        <form method="post" action="<?= $e($base . '/minha-conta/aparelhos/' . (int) $a['id'] . '/sair') ?>">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn-icone is-perigo" type="submit"><?= Icon::svg('log-out', 'icon-sm') ?><span>Desconectar</span></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($aparelhos) > 1 || ($seletorAtual === null && $aparelhos !== [])): ?>
                <form class="bloco-rodape" method="post" action="<?= $e($base . '/minha-conta/aparelhos/sair-todos') ?>"
                      data-confirm="Todos os outros aparelhos vão pedir a senha no próximo acesso." data-confirm-titulo="Desconectar os outros aparelhos?" data-confirm-acao="Desconectar todos">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('log-out', 'icon-sm') ?>Desconectar todos os outros</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($acessos !== []): ?>
        <section class="bloco" aria-labelledby="titulo-acessos">
            <div class="bloco-cabecalho"><h2 id="titulo-acessos"><?= Icon::svg('activity') ?>Últimos acessos</h2></div>
            <ul class="historico">
                <?php foreach ($acessos as $a): $alerta = $a['titulo'] !== 'Login' && $a['titulo'] !== 'Login automático'; ?>
                    <li>
                        <span><strong class="<?= $alerta ? 'texto-alerta' : '' ?>"><?= $e((string) $a['titulo']) ?></strong> · <?= $e(mb_strimwidth((string) $a['descricao'], 0, 110, '...')) ?></span>
                        <span class="texto-suave"><?= $e($quando((string) $a['data_hora'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>
