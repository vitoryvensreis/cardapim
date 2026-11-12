<?php
/** Configuracoes da marca e do e-mail. Espera: $atuais, $valores, $erros, $base, $csrf, $email, $emailErro, $emailPara. */
use Cardapim\Core\Icon;
use Cardapim\Services\Configuracoes;
use Cardapim\Services\Plataforma\MarcaService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn (string $c): string => is_string($valores[$c] ?? null) ? (string) $valores[$c] : '';
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Configurações</h1>
            <p>Nome, textos e logos da plataforma. O que você salvar aqui aparece em todo o sistema: site, painéis, cardápios, WhatsApp e e-mails. No fim da página, teste o envio de e-mail.</p>
        </div>
    </header>

    <?= Campo::resumoErros($erros) ?>

    <section class="bloco" aria-labelledby="titulo-nome">
        <div class="bloco-cabecalho">
            <h2 id="titulo-nome"><?= Icon::svg('type') ?>Nome e textos</h2>
            <p>O nome aparece nos títulos das páginas, nas mensagens de WhatsApp, nos e-mails e no app instalado no celular.</p>
        </div>
        <form method="post" action="<?= $e($base . '/configuracoes/textos') ?>" novalidate data-validate-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <div class="form-grade">
                <?php foreach (MarcaService::TEXTOS as $chave => [$rotulo, $max]): ?>
                    <?= Campo::texto(['nome' => $chave, 'rotulo' => $rotulo, 'valor' => $v($chave), 'icone' => $chave === 'nome_sistema' ? 'store' : 'type',
                        'erro' => $erros[$chave] ?? null, 'opcional' => $chave !== 'nome_sistema', 'classe' => $chave === 'nome_sistema' ? 'col-inteira' : '',
                        'dica' => match ($chave) {
                            'nome_sistema' => 'Ex.: ' . Configuracoes::NOME_PADRAO . '. Até 40 letras.',
                            'descricao_padrao' => 'Usada quando uma página não tem descrição própria (Google e redes sociais).',
                            default => 'Aparece no rodapé do site.',
                        },
                        'attrs' => ['maxlength' => $max, 'autocomplete' => 'off']]) ?>
                <?php endforeach; ?>
            </div>
            <div class="form-acoes">
                <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('check', 'icon-sm') ?><span>Salvar textos</span></button>
            </div>
        </form>
    </section>

    <section class="bloco" aria-labelledby="titulo-logos">
        <div class="bloco-cabecalho">
            <h2 id="titulo-logos"><?= Icon::svg('palette') ?>Logos e ícones</h2>
            <p>Use os arquivos oficiais da marca. SVG fica mais nítido; PNG com fundo transparente também serve (até 2 MB).</p>
        </div>
        <div class="marcas-grade">
            <?php foreach (MarcaService::LOGOS as $chave => [$rotulo, $onde, $fundo, $svg]):
                $atual = $atuais[$chave];
                $padrao = $atual === Configuracoes::LOGO_FALLBACK[$chave];
            ?>
                <article class="marca-cartao" id="logo-<?= $e($chave) ?>" data-logo>
                    <div class="marca-previa is-<?= $e($fundo) ?><?= str_starts_with($chave, 'icone') ? ' is-icone' : '' ?>">
                        <img src="<?= $e(\Cardapim\Shared\MidiaUrl::publica((string) $atual)) ?>" alt="<?= $e($rotulo . ' atual') ?>" data-logo-previa>
                    </div>
                    <div class="marca-info">
                        <h3><?= $e($rotulo) ?></h3>
                        <p class="texto-suave"><?= $e($onde) ?></p>
                        <p class="marca-origem"><?= $padrao ? Icon::svg('badge-check', 'icon-sm') . 'Arquivo original' : Icon::svg('upload', 'icon-sm') . 'Enviada por você' ?></p>
                    </div>
                    <form class="marca-envio" method="post" action="<?= $e($base . '/configuracoes/logo') ?>" enctype="multipart/form-data" data-logo-form>
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="chave" value="<?= $e($chave) ?>">
                        <label class="btn btn-outline btn-sm marca-escolher">
                            <?= Icon::svg('image', 'icon-sm') ?>Escolher arquivo
                            <input class="sr-only" type="file" name="arquivo" accept="<?= $svg ? '.svg,image/svg+xml,' : '' ?>image/png,image/jpeg,image/webp" required>
                        </label>
                        <span class="texto-suave marca-nome" data-logo-nome></span>
                        <button class="btn btn-primary btn-sm" type="submit" data-logo-enviar disabled><?= Icon::svg('upload', 'icon-sm') ?>Enviar</button>
                    </form>
                    <?php if (!$padrao): ?>
                        <form method="post" action="<?= $e($base . '/configuracoes/logo/padrao') ?>"
                              data-confirm="Volta para o arquivo original do sistema." data-confirm-titulo="Voltar ao padrão?" data-confirm-acao="Voltar ao padrão">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="chave" value="<?= $e($chave) ?>">
                            <button class="link-acao" type="submit"><?= Icon::svg('rotate-ccw', 'icon-sm') ?>Voltar ao padrão</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="texto-suave"><?= Icon::svg('info', 'icon-sm') ?>Quem já instalou o app no celular vê o ícone novo depois de instalar de novo.</p>
    </section>

    <section class="bloco" aria-labelledby="titulo-email" id="email">
        <div class="bloco-cabecalho">
            <h2 id="titulo-email"><?= Icon::svg('mail') ?>E-mail do sistema</h2>
            <p>Usado nos avisos de chamados, na recuperação de senha e nos códigos de acesso. O servidor e a senha ficam no arquivo de configuração do servidor, nunca nesta tela.</p>
        </div>
        <?php
        $emailPronto = $email['driver'] === 'smtp' && !empty($email['host']);
        $emailModo = match ($email['driver']) {
            'smtp' => 'Servidor de e-mail (SMTP)',
            'log' => 'Somente registro (modo de desenvolvimento: nada é enviado)',
            default => 'Envio simples do servidor (sem assinatura DKIM, pode cair no spam)',
        };
        ?>
        <div class="resumo-assinatura">
            <div class="resumo-linha"><span>Situação</span><strong><?php if ($emailPronto): ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Configurado</span><?php else: ?><span class="etiqueta etiqueta-atencao"><?= Icon::svg('triangle-exclamation-point') ?>Sem servidor de e-mail</span><?php endif; ?></strong></div>
            <div class="resumo-linha"><span>Como envia</span><strong><?= $e($emailModo) ?></strong></div>
            <?php if ($email['driver'] === 'smtp'): ?>
                <div class="resumo-linha"><span>Servidor</span><strong><?= $e((string) ($email['host'] ?? '—')) ?>:<?= (int) $email['porta'] ?> (<?= $e(match ($email['seguranca']) { 'ssl' => 'SSL', 'tls' => 'TLS', default => 'sem criptografia' }) ?>)</strong></div>
                <div class="resumo-linha"><span>Conta</span><strong><?= $e((string) ($email['usuario'] ?: '—')) ?></strong></div>
            <?php endif; ?>
            <div class="resumo-linha"><span>Remetente</span><strong><?= $e((string) $email['remetente']) ?></strong></div>
        </div>
        <?php if (is_string($emailErro) && $emailErro !== ''): ?>
            <p class="config-email-erro" role="alert"><?= Icon::svg('circle-x', 'icon-sm') ?><span><strong>Motivo da última falha:</strong> <?= $e($emailErro) ?></span></p>
        <?php endif; ?>
        <form method="post" action="<?= $e($base . '/configuracoes/email-teste') ?>" class="config-email-teste" novalidate data-validate-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?= Campo::texto(['nome' => 'para', 'rotulo' => 'Mandar um teste para', 'valor' => (string) $emailPara, 'icone' => 'mail', 'tipo' => 'email',
                'dica' => 'Chega em até um minuto. Se não aparecer, confira o spam.', 'attrs' => ['maxlength' => 190, 'autocomplete' => 'email', 'required' => true]]) ?>
            <div class="form-acoes">
                <button class="btn btn-outline" type="submit" data-submit-label="Enviando..."><?= Icon::svg('send', 'icon-sm') ?><span>Enviar e-mail de teste</span></button>
            </div>
        </form>
    </section>
</div>
