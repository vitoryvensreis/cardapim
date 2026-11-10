<?php
/**
 * Asaas. Espera: $integracao, $urlWebhook, $tokenWebhook (so quando os avisos nao foram configurados),
 * $eventos, $erros, $valores, $base, $csrf. Opcional: $plataforma (true = Asaas da administracao do Cardapim).
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (?string $v): string => $v ? date('d/m/Y H:i', (int) strtotime($v)) : '-';
$conectado = $integracao['status'] !== 'desconectado';
$d = $integracao['dados'];
$webhookOk = !empty($d['webhook_asaas_id']);
$statusIntegracao = $integracao['status'];
$nomesEventos = [
    'PAYMENT_CREATED' => 'Cobrança criada', 'PAYMENT_RECEIVED' => 'Pagamento recebido', 'PAYMENT_CONFIRMED' => 'Pagamento confirmado',
    'PAYMENT_OVERDUE' => 'Cobrança vencida', 'PAYMENT_REFUNDED' => 'Pagamento estornado', 'PAYMENT_DELETED' => 'Cobrança removida',
    'PAYMENT_UPDATED' => 'Cobrança alterada',
];
$plataforma = !empty($plataforma);
$erroGeral = $erros['_geral'] ?? null;
unset($erros['_geral']);

$formulario = static function () use ($e, $base, $csrf, $valores, $erros, $conectado): void {
    ?>
    <form method="post" action="<?= $e($base . '/integracoes/asaas') ?>" novalidate data-validate-form autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <fieldset class="opcoes-modo">
            <legend class="rotulo-grupo">Qual conta você vai conectar?</legend>
            <label class="opcao-modo">
                <input type="radio" name="ambiente" value="producao"<?= ($valores['ambiente'] ?? 'producao') !== 'sandbox' ? ' checked' : '' ?>>
                <span><strong>Conta real (Produção)</strong><small>Para receber pagamentos de verdade.</small></span>
            </label>
            <label class="opcao-modo">
                <input type="radio" name="ambiente" value="sandbox"<?= ($valores['ambiente'] ?? '') === 'sandbox' ? ' checked' : '' ?>>
                <span><strong>Conta de testes (Sandbox)</strong><small>Para testar sem dinheiro real. A chave começa diferente.</small></span>
            </label>
            <?php if (isset($erros['ambiente'])): ?><p class="field-msg ficha-erro"><?= $e($erros['ambiente']) ?></p><?php endif; ?>
        </fieldset>
        <?= Campo::texto(['nome' => 'chave', 'rotulo' => 'Chave de API do Asaas', 'tipo' => 'password', 'icone' => 'key-round', 'erro' => $erros['chave'] ?? null, 'senha' => true,
            'dica' => 'Fica guardada criptografada. Depois de salvar, aparecem só os 4 últimos caracteres.',
            'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 400, 'data-validate' => 'required', 'placeholder' => '$aact_...']]) ?>
        <?= Campo::texto(['nome' => 'email_avisos', 'rotulo' => 'E-mail para alertas do Asaas', 'tipo' => 'email', 'valor' => $valores['email_avisos'] ?? '', 'icone' => 'mail', 'opcional' => true,
            'dica' => 'O Asaas avisa neste e-mail se não conseguir falar com o Cardapim.', 'attrs' => ['maxlength' => 190, 'autocomplete' => 'email']]) ?>
        <div class="bloco-rodape">
            <button class="btn btn-primary" type="submit" data-submit-label="Conferindo a chave..."><?= Icon::svg('link', 'icon-sm') ?><?= $conectado ? 'Salvar nova chave' : 'Conectar conta Asaas' ?></button>
        </div>
    </form>
    <?php
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>Asaas</h1>
            <p><?= $plataforma ? 'Conta Asaas da plataforma: recebe o pagamento das assinaturas dos restaurantes.' : 'Receba o Pix dos pedidos online direto na conta Asaas do seu restaurante.' ?></p>
        </div>
        <?php require __DIR__ . '/_status.php'; ?>
    </header>

    <?php if ($erroGeral !== null && $erros === []): ?>
        <div class="notice notice-warning erros-form" role="alert" tabindex="-1" data-erros-form><?= Icon::svg('circle-x') ?><div><strong>Não deu certo</strong><p><?= $e($erroGeral) ?></p></div></div>
    <?php endif; ?>

    <?php if (!$conectado): ?>
        <section class="bloco" aria-labelledby="titulo-como">
            <div class="bloco-cabecalho"><h2 id="titulo-como"><?= Icon::svg('info') ?>Como conectar</h2></div>
            <ol class="passos-2fa">
                <li><strong>Entre na sua conta do Asaas</strong> e abra <strong>Integrações &gt; Chaves de API</strong>.</li>
                <li><strong>Gere uma chave nova</strong> e copie. Guarde em lugar seguro: o Asaas pode não mostrá-la de novo.</li>
                <li><strong>Cole aqui embaixo</strong>. O Cardapim confere a chave e já configura os avisos de pagamento.</li>
            </ol>
        </section>
        <section class="bloco" aria-labelledby="titulo-conectar">
            <div class="bloco-cabecalho"><h2 id="titulo-conectar"><?= Icon::svg('wallet') ?>Conectar conta</h2></div>
            <?php $formulario(); ?>
        </section>
    <?php else: ?>
        <section class="bloco" aria-labelledby="titulo-conta">
            <div class="bloco-cabecalho"><h2 id="titulo-conta"><?= Icon::svg('wallet') ?>Conta conectada</h2></div>
            <?php if ($integracao['ultimo_erro']): ?>
                <div class="notice notice-warning"><?= Icon::svg('circle-exclamation-point') ?><div><p><?= $e((string) $integracao['ultimo_erro']) ?></p></div></div>
            <?php endif; ?>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Conta</span><strong><?= $e((string) (($d['conta_nome'] ?? '') ?: 'Nome não informado pelo Asaas')) ?></strong></div>
                <div class="resumo-linha"><span>Ambiente</span><strong><?= $integracao['ambiente'] === 'sandbox' ? 'Sandbox (testes)' : 'Produção (conta real)' ?></strong></div>
                <div class="resumo-linha"><span>Chave de API</span><strong class="chave-curta"><?= $e((string) ($d['chave_final'] ?? '...')) ?></strong></div>
                <div class="resumo-linha"><span>Conectada em</span><strong><?= $e($data($integracao['conectado_em'])) ?></strong></div>
                <div class="resumo-linha"><span>Última verificação</span><strong><?= $e($data($integracao['verificado_em'])) ?></strong></div>
            </div>
            <div class="acoes-2fa">
                <form method="post" action="<?= $e($base . '/integracoes/asaas/testar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Testar conexão</button>
                </form>
                <details class="acao-senha"<?= $erros !== [] ? ' open' : '' ?>>
                    <summary class="btn btn-outline btn-sm"><?= Icon::svg('key-round', 'icon-sm') ?>Trocar a chave</summary>
                    <?php $formulario(); ?>
                </details>
                <form method="post" action="<?= $e($base . '/integracoes/asaas/desconectar') ?>"
                      data-confirm="A chave será apagada do Cardapim e os avisos de pagamento param de chegar." data-confirm-titulo="Desconectar o Asaas?" data-confirm-acao="Desconectar" data-confirm-perigo="1">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('unplug', 'icon-sm') ?>Desconectar</button>
                </form>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-avisos">
            <div class="bloco-cabecalho">
                <h2 id="titulo-avisos"><?= Icon::svg('webhook') ?>Avisos de pagamento</h2>
                <p>O Asaas avisa o Cardapim quando um Pix é criado, pago, vence ou é estornado (webhook).</p>
            </div>
            <?php if ($webhookOk): ?>
                <p><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Configurados</span>
                    <span class="texto-suave">desde <?= $e($data((string) ($d['webhook_configurado_em'] ?? ''))) ?></span></p>
                <form class="bloco-rodape" method="post" action="<?= $e($base . '/integracoes/asaas/webhook') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('refresh-cw', 'icon-sm') ?>Configurar de novo</button>
                </form>
            <?php else: ?>
                <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Os avisos ainda não estão configurados.</strong><p>Toque no botão para o Cardapim configurar sozinho. Se não der certo, configure no Asaas com os dados abaixo.</p></div></div>
                <form method="post" action="<?= $e($base . '/integracoes/asaas/webhook') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary btn-sm" type="submit" data-submit-label="Configurando..."><?= Icon::svg('webhook', 'icon-sm') ?>Configurar automaticamente</button>
                </form>
                <?php if ($urlWebhook !== null): ?>
                    <details class="manual">
                        <summary>Configurar manualmente no Asaas</summary>
                        <p class="texto-suave">No Asaas: Integrações &gt; Webhooks &gt; Criar Webhook. Use a versão 3 da API e marque os eventos de cobrança.</p>
                        <div class="copiar-linha">
                            <span>Endereço (URL)</span>
                            <code><?= $e($urlWebhook) ?></code>
                            <button class="link-acao" type="button" data-copiar="<?= $e($urlWebhook) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar</button>
                        </div>
                        <?php if ($tokenWebhook): ?>
                            <div class="copiar-linha">
                                <span>Token de autenticação</span>
                                <code data-segredo hidden><?= $e($tokenWebhook) ?></code>
                                <code data-segredo-mascara>••••••••••••••••</code>
                                <button class="link-acao" type="button" data-mostrar-segredo><?= Icon::svg('eye', 'icon-sm') ?>Mostrar</button>
                                <button class="link-acao" type="button" data-copiar="<?= $e($tokenWebhook) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar</button>
                            </div>
                        <?php endif; ?>
                        <span class="texto-suave" data-copiado role="status"></span>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-eventos">
            <div class="bloco-cabecalho"><h2 id="titulo-eventos"><?= Icon::svg('activity') ?>Últimos avisos recebidos</h2></div>
            <?php if ($eventos === []): ?>
                <p class="texto-suave">Nenhum aviso ainda. Eles aparecem aqui quando uma cobrança for criada ou paga no Asaas.</p>
            <?php else: ?>
                <ul class="historico">
                    <?php foreach ($eventos as $ev): ?>
                        <li><span><strong><?= $e($nomesEventos[$ev['evento']] ?? (string) $ev['evento']) ?></strong> <span class="texto-suave"><?= $e((string) $ev['evento']) ?></span></span><span class="texto-suave"><?= $e($data((string) $ev['recebido_em'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
