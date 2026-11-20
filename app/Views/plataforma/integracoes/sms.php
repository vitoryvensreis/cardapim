<?php
/**
 * SMS da plataforma (SMSGate, celular Android): reserva automatica dos codigos de login quando o WhatsApp falha.
 * Espera: $sms (SmsGatewayService::resumo), $erros, $valores, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (string $v): string => $v !== '' ? date('d/m/Y H:i', (int) strtotime($v)) : 'Ainda não enviada';
$salvoNoPainel = $sms['origem'] === 'painel';
$configurado = $sms['origem'] !== '';
$acao = $base . '/integracoes/sms';
$erroGeral = $erros['_geral'] ?? null;
unset($erros['_geral']);
$errosTeste = array_intersect_key($erros, ['telefone' => 1]);
$errosConfig = array_diff_key($erros, $errosTeste);
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>SMS da plataforma</h1>
            <p>Se o WhatsApp da plataforma estiver desconectado ou não conseguir entregar, o código de login do cliente vai por SMS automaticamente, pelo chip do celular Android conectado.</p>
        </div>
        <?php if ($configurado): ?>
            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Configurado</span>
        <?php else: ?>
            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Não configurado</span>
        <?php endif; ?>
    </header>

    <?php if ($erroGeral !== null && $errosConfig === []): ?>
        <div class="notice notice-warning erros-form" role="alert" tabindex="-1" data-erros-form><?= Icon::svg('circle-x') ?><div><strong>Não deu certo</strong><p><?= $e($erroGeral) ?></p></div></div>
    <?php endif; ?>

    <?php if ($salvoNoPainel): ?>
        <section class="bloco" aria-labelledby="titulo-atual">
            <div class="bloco-cabecalho"><h2 id="titulo-atual"><?= Icon::svg('smartphone') ?>Configuração atual</h2></div>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Endereço</span><strong class="chave-curta"><?= $e($sms['url']) ?></strong></div>
                <div class="resumo-linha"><span>Usuário do app</span><strong class="chave-curta"><?= $e($sms['usuario']) ?></strong></div>
                <div class="resumo-linha"><span>Senha</span><strong>Guardada com criptografia</strong></div>
                <div class="resumo-linha"><span>Salva em</span><strong><?= $e($sms['salvo_em'] !== '' ? date('d/m/Y H:i', (int) strtotime($sms['salvo_em'])) : '-') ?><?= $sms['salvo_por'] !== '' ? ' por ' . $e($sms['salvo_por']) : '' ?></strong></div>
                <div class="resumo-linha"><span>Último teste</span><strong><?= $e($data($sms['ultimo_teste'])) ?></strong></div>
            </div>
        </section>
    <?php elseif ($sms['origem'] === 'env'): ?>
        <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Usando o arquivo de configuração do servidor</strong><p>Hoje o SMS vem de SMSGATE_URL, SMSGATE_USUARIO e SMSGATE_SENHA no servidor. Se você salvar aqui, o painel passa a valer no lugar deles.</p></div></div>
    <?php else: ?>
        <section class="bloco" aria-labelledby="titulo-como">
            <div class="bloco-cabecalho"><h2 id="titulo-como"><?= Icon::svg('info') ?>Onde encontrar</h2></div>
            <ol class="passos-2fa">
                <li><strong>Abra o app SMSGate no celular</strong> e confira se o serviço está ligado (botão "Stop Service").</li>
                <li><strong>Vá em Settings > Cloud Server</strong> e copie o usuário e a senha.</li>
                <li><strong>Cole aqui embaixo</strong> e envie uma mensagem de teste.</li>
            </ol>
        </section>
    <?php endif; ?>

    <section class="bloco" aria-labelledby="titulo-form">
        <div class="bloco-cabecalho">
            <h2 id="titulo-form"><?= Icon::svg($salvoNoPainel ? 'settings' : 'key-round') ?><?= $salvoNoPainel ? 'Alterar configuração' : 'Configurar SMS' ?></h2>
        </div>
        <?= Campo::resumoErros($errosConfig) ?>
        <form method="post" action="<?= $e($acao) ?>" novalidate data-validate-form autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?= Campo::texto(['nome' => 'url', 'rotulo' => 'Endereço do servidor de SMS', 'valor' => $valores['url'] ?? '', 'icone' => 'link', 'erro' => $errosConfig['url'] ?? null,
                'dica' => 'Com https://. Pode colar só o domínio: o caminho da API é completado sozinho.',
                'attrs' => ['inputmode' => 'url', 'autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 200, 'data-validate' => 'required']]) ?>
            <?= Campo::texto(['nome' => 'usuario', 'rotulo' => 'Usuário do app', 'valor' => $valores['usuario'] ?? '', 'icone' => 'user', 'erro' => $errosConfig['usuario'] ?? null,
                'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 100, 'data-validate' => 'required']]) ?>
            <?= Campo::texto(['nome' => 'senha', 'rotulo' => 'Senha do app', 'tipo' => 'password', 'icone' => 'key-round', 'erro' => $errosConfig['senha'] ?? null, 'senha' => true,
                'opcional' => $salvoNoPainel,
                'dica' => $salvoNoPainel ? 'Deixe em branco para manter a senha salva.' : 'Fica guardada com criptografia e não aparece mais na tela.',
                'attrs' => ['autocomplete' => 'new-password', 'spellcheck' => 'false', 'maxlength' => 200] + ($salvoNoPainel ? [] : ['data-validate' => 'required'])]) ?>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar</button>
            </div>
        </form>
    </section>

    <section class="bloco" id="teste" aria-labelledby="titulo-teste"<?= $configurado ? '' : ' hidden' ?>>
        <div class="bloco-cabecalho">
            <h2 id="titulo-teste"><?= Icon::svg('send') ?>Enviar SMS de teste</h2>
            <p>Mande um SMS para qualquer celular e confira se chegou. Até 10 testes por hora.</p>
        </div>
        <?= Campo::resumoErros($errosTeste) ?>
        <form method="post" action="<?= $e($acao . '/teste') ?>" novalidate data-validate-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?= Campo::texto(['nome' => 'telefone', 'rotulo' => 'Celular que vai receber', 'valor' => $valores['telefone'] ?? '', 'icone' => 'phone', 'erro' => $errosTeste['telefone'] ?? null,
                'dica' => 'Com DDD. Exemplo: (85) 99999-0000', 'attrs' => ['inputmode' => 'tel', 'data-mask' => 'phone', 'data-validate' => 'phone', 'autocomplete' => 'off', 'placeholder' => '(00) 00000-0000']]) ?>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?>Enviar teste</button>
            </div>
        </form>
    </section>

    <?php if ($salvoNoPainel): ?>
        <section class="bloco" aria-labelledby="titulo-remover">
            <div class="bloco-cabecalho">
                <h2 id="titulo-remover"><?= Icon::svg('unplug') ?>Apagar configuração</h2>
                <p>Use se trocar de celular ou de servidor. Sem SMS, os códigos de login vão só pelo WhatsApp.</p>
            </div>
            <form method="post" action="<?= $e($acao . '/remover') ?>"
                  data-confirm="A configuração do SMS será apagada do painel. Se o WhatsApp da plataforma falhar, o cliente não consegue entrar." data-confirm-titulo="Apagar configuração do SMS?" data-confirm-acao="Apagar" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('unplug', 'icon-sm') ?>Apagar configuração</button>
            </form>
        </section>
    <?php endif; ?>
</div>
