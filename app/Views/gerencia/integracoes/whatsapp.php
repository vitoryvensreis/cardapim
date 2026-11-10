<?php
/** WhatsApp (WPPConnect). Espera: $integracao, $disponivel, $erros, $valores, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (?string $v): string => $v ? date('d/m/Y H:i', (int) strtotime($v)) : '-';
$statusIntegracao = $integracao['status'];
$numero = (string) ($integracao['dados']['numero'] ?? '');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>WhatsApp</h1>
            <p>Conecte o WhatsApp do restaurante para enviar avisos aos clientes e, depois, atender por ele.</p>
        </div>
        <?php require __DIR__ . '/_status.php'; ?>
    </header>

    <?php if (!$disponivel): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('clock') ?>
                <strong>O WhatsApp ainda não foi liberado neste servidor</strong>
                <p>A equipe do Cardapim está preparando o servidor do WhatsApp. Assim que estiver pronto, o botão de conectar aparece aqui.</p>
            </div>
        </section>
    <?php elseif ($statusIntegracao === 'desconectado'): ?>
        <section class="bloco" aria-labelledby="titulo-como">
            <div class="bloco-cabecalho"><h2 id="titulo-como"><?= Icon::svg('info') ?>Como funciona</h2></div>
            <ol class="passos-2fa">
                <li><strong>Toque em "Conectar WhatsApp".</strong> Vai aparecer um QR Code.</li>
                <li><strong>No celular do restaurante</strong>, abra o WhatsApp &gt; <strong>Aparelhos conectados</strong> &gt; <strong>Conectar um aparelho</strong>.</li>
                <li><strong>Aponte a câmera para o QR Code.</strong> Pronto: o Cardapim passa a usar esse WhatsApp.</li>
            </ol>
            <p class="texto-suave">Use de preferência um número só do restaurante (WhatsApp ou WhatsApp Business). O celular precisa ficar com internet.</p>
            <form class="bloco-rodape" method="post" action="<?= $e($base . '/integracoes/whatsapp/conectar') ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary" type="submit" data-submit-label="Preparando..."><?= Icon::svg('qr-code', 'icon-sm') ?>Conectar WhatsApp</button>
            </form>
        </section>
    <?php else: ?>
        <section class="bloco whatsapp-conexao" aria-labelledby="titulo-conexao" aria-live="polite"
                 data-whatsapp-estado="<?= $e($base . '/integracoes/whatsapp/estado') ?>" data-estado-inicial="<?= $e($statusIntegracao) ?>">
            <div class="bloco-cabecalho"><h2 id="titulo-conexao"><?= Icon::svg('message-circle') ?>Conexão</h2></div>

            <div class="whatsapp-painel" data-painel="carregando"<?= $statusIntegracao === 'conectado' ? ' hidden' : '' ?>>
                <span class="whatsapp-carregando" aria-hidden="true"><?= Icon::svg('loader-circle', 'icon-lg') ?></span>
                <p data-texto-estado>Preparando o WhatsApp...</p>
            </div>

            <div class="whatsapp-painel whatsapp-qr" data-painel="qrcode" hidden>
                <div class="qr-codigo"><img alt="QR Code para conectar o WhatsApp" data-qr-imagem width="220" height="220"></div>
                <ol class="passos-2fa">
                    <li>Abra o <strong>WhatsApp</strong> no celular do restaurante.</li>
                    <li>Toque em <strong>Mais opções</strong> ou <strong>Configurações</strong> &gt; <strong>Aparelhos conectados</strong>.</li>
                    <li>Toque em <strong>Conectar um aparelho</strong> e aponte para este código.</li>
                </ol>
                <p class="texto-suave">O código muda sozinho de tempos em tempos. Deixe esta tela aberta.</p>
            </div>

            <div class="whatsapp-painel" data-painel="expirado" hidden>
                <p data-texto-estado>O QR Code venceu.</p>
                <form method="post" action="<?= $e($base . '/integracoes/whatsapp/conectar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary btn-sm" type="submit" data-submit-label="Gerando..."><?= Icon::svg('refresh-cw', 'icon-sm') ?>Gerar novo QR Code</button>
                </form>
            </div>

            <div class="whatsapp-painel" data-painel="conectado"<?= $statusIntegracao === 'conectado' ? '' : ' hidden' ?>>
                <div class="resumo-assinatura">
                    <div class="resumo-linha"><span>Situação</span><strong><span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Conectado</span></strong></div>
                    <div class="resumo-linha"><span>Número</span><strong data-numero><?= $numero !== '' ? $e(Documento::formatarTelefone($numero)) : 'Conectado' ?></strong></div>
                    <div class="resumo-linha"><span>Conectado em</span><strong><?= $e($data($integracao['conectado_em'])) ?></strong></div>
                </div>
            </div>

            <div class="whatsapp-painel" data-painel="erro" hidden>
                <div class="notice notice-warning"><?= Icon::svg('circle-exclamation-point') ?><div><p data-texto-estado><?= $e((string) ($integracao['ultimo_erro'] ?? 'Não foi possível falar com o WhatsApp.')) ?></p></div></div>
                <form method="post" action="<?= $e($base . '/integracoes/whatsapp/conectar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-primary btn-sm" type="submit" data-submit-label="Preparando..."><?= Icon::svg('qr-code', 'icon-sm') ?>Conectar de novo</button>
                </form>
            </div>

            <form class="bloco-rodape" method="post" action="<?= $e($base . '/integracoes/whatsapp/desconectar') ?>"
                  data-confirm="O celular sai do Cardapim. Para voltar, será preciso ler um novo QR Code." data-confirm-titulo="Desconectar o WhatsApp?" data-confirm-acao="Desconectar" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('unplug', 'icon-sm') ?><?= $statusIntegracao === 'conectado' ? 'Desconectar' : 'Cancelar conexão' ?></button>
            </form>
        </section>

        <section class="bloco" id="teste" aria-labelledby="titulo-teste" data-so-conectado<?= $statusIntegracao === 'conectado' ? '' : ' hidden' ?>>
            <div class="bloco-cabecalho">
                <h2 id="titulo-teste"><?= Icon::svg('send') ?>Enviar mensagem de teste</h2>
                <p>Mande uma mensagem para qualquer número e confira se chegou. Até 10 testes por hora.</p>
            </div>
            <?= Campo::resumoErros($erros) ?>
            <form method="post" action="<?= $e($base . '/integracoes/whatsapp/teste') ?>" novalidate data-validate-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <?= Campo::texto(['nome' => 'telefone', 'rotulo' => 'Número que vai receber', 'valor' => $valores['telefone'] ?? '', 'icone' => 'phone', 'erro' => $erros['telefone'] ?? null,
                    'dica' => 'Com DDD. Exemplo: (85) 99999-0000', 'attrs' => ['inputmode' => 'tel', 'data-mask' => 'phone', 'data-validate' => 'phone', 'autocomplete' => 'off', 'placeholder' => '(00) 00000-0000']]) ?>
                <?= Campo::areaTexto(['nome' => 'mensagem', 'rotulo' => 'Mensagem', 'valor' => $valores['mensagem'] ?? '', 'erro' => $erros['mensagem'] ?? null,
                    'attrs' => ['maxlength' => 1000, 'rows' => 3, 'required' => true]]) ?>
                <div class="bloco-rodape">
                    <button class="btn btn-primary" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?>Enviar teste</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>
