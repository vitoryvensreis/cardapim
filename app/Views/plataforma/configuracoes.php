<?php
/** Configuracoes da marca e do e-mail. Espera: $atuais, $valores, $erros, $base, $csrf, $email, $emailErro, $emailPara. */
use Cardapim\Core\Icon;
use Cardapim\Services\Configuracoes;
use Cardapim\Services\Plataforma\MarcaService;
use Cardapim\Services\Seo\Seo;
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

    <section class="bloco" aria-labelledby="titulo-seo" id="seo">
        <div class="bloco-cabecalho">
            <h2 id="titulo-seo"><?= Icon::svg('share-2') ?>Google e redes sociais</h2>
            <p>Como o site aparece no Google e quando alguém manda o link no WhatsApp, Instagram, Facebook ou Telegram. Os cardápios dos restaurantes já saem com uma imagem própria, com a logo, o nome e a cidade de cada um.</p>
        </div>
        <?php
        $seoPadrao = [
            'seo_titulo' => Seo::tituloPadrao(),
            'seo_descricao' => Seo::DESCRICAO_PADRAO,
            'frase_compartilhamento' => Seo::FRASE_PADRAO,
            'google_verificacao' => '',
        ];
        $seoMostrar = static fn (string $c): string => $v($c) !== '' ? $v($c) : $seoPadrao[$c];
        ?>
        <div class="seo-grade">
            <div class="seo-previa" data-logo>
                <p class="seo-previa-rotulo"><?= Icon::svg('message-circle', 'icon-sm') ?>Assim o link aparece no WhatsApp</p>
                <div class="seo-bolha">
                    <div class="seo-cartao">
                        <img src="<?= $e($previaImagem) ?>" width="1200" height="630" alt="Imagem que aparece no link" data-logo-previa>
                        <div class="seo-cartao-texto">
                            <strong data-seo-previa="seo_titulo"><?= $e($seoMostrar('seo_titulo')) ?></strong>
                            <span data-seo-previa="seo_descricao"><?= $e($seoMostrar('seo_descricao')) ?></span>
                            <small><?= $e($dominio !== '' ? $dominio : 'seu-site.com.br') ?></small>
                        </div>
                    </div>
                    <p class="seo-bolha-link"><?= $e('https://' . ($dominio !== '' ? $dominio : 'seu-site.com.br')) ?></p>
                </div>
                <p class="marca-origem"><?= $imagemEnviada
                    ? Icon::svg('upload', 'icon-sm') . 'Imagem enviada por você'
                    : Icon::svg('sparkles', 'icon-sm') . 'Imagem automática: ícone do app, nome e frase' ?></p>
                <p class="texto-suave seo-dica"><?= $imagemEnviada
                    ? 'Para voltar à imagem que se atualiza sozinha, use "Voltar à imagem automática".'
                    : 'Ela muda sozinha quando você troca o nome, a frase ou o ícone do app. Se preferir, envie uma arte pronta.' ?></p>
                <form class="marca-envio" method="post" action="<?= $e($base . '/configuracoes/compartilhamento') ?>" enctype="multipart/form-data" data-logo-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <label class="btn btn-outline btn-sm marca-escolher">
                        <?= Icon::svg('image', 'icon-sm') ?>Escolher imagem
                        <input class="sr-only" type="file" name="arquivo" accept="image/png,image/jpeg,image/webp" required aria-describedby="seo-imagem-dica">
                    </label>
                    <span class="texto-suave marca-nome" data-logo-nome></span>
                    <button class="btn btn-primary btn-sm" type="submit" data-logo-enviar disabled><?= Icon::svg('upload', 'icon-sm') ?>Enviar</button>
                </form>
                <p class="texto-suave seo-dica" id="seo-imagem-dica">PNG, JPG ou WebP de até 2 MB. Tamanho ideal: 1200 x 630 pixels; imagens em outro formato são recortadas pelo centro.</p>
                <?php if ($imagemEnviada): ?>
                    <form method="post" action="<?= $e($base . '/configuracoes/compartilhamento/padrao') ?>"
                          data-confirm="O link volta a usar a imagem automática, com o ícone, o nome e a frase." data-confirm-titulo="Voltar à imagem automática?" data-confirm-acao="Voltar à automática">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="link-acao" type="submit"><?= Icon::svg('rotate-ccw', 'icon-sm') ?>Voltar à imagem automática</button>
                    </form>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= $e($base . '/configuracoes/seo') ?>" class="seo-form" novalidate data-validate-form>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <?= Campo::texto(['nome' => 'seo_titulo', 'rotulo' => 'Título da página inicial', 'valor' => $v('seo_titulo'), 'icone' => 'type', 'opcional' => true,
                    'erro' => $erros['seo_titulo'] ?? null, 'dica' => 'Aparece em azul no Google e em negrito no link. Até ' . Seo::TITULO_MAX . ' letras; vazio usa o padrão.',
                    'attrs' => ['maxlength' => Seo::TITULO_MAX, 'placeholder' => $seoPadrao['seo_titulo'], 'autocomplete' => 'off', 'data-seo-campo' => 'seo_titulo']]) ?>
                <?= Campo::areaTexto(['nome' => 'seo_descricao', 'rotulo' => 'Descrição da página inicial', 'valor' => $v('seo_descricao'), 'opcional' => true,
                    'erro' => $erros['seo_descricao'] ?? null, 'dica' => 'Resumo do que o sistema faz, embaixo do título. Até ' . Seo::DESCRICAO_MAX . ' letras.',
                    'attrs' => ['maxlength' => Seo::DESCRICAO_MAX, 'rows' => 3, 'placeholder' => $seoPadrao['seo_descricao'], 'data-seo-campo' => 'seo_descricao']]) ?>
                <?= Campo::texto(['nome' => 'frase_compartilhamento', 'rotulo' => 'Frase da imagem automática', 'valor' => $v('frase_compartilhamento'), 'icone' => 'sparkles', 'opcional' => true,
                    'erro' => $erros['frase_compartilhamento'] ?? null, 'dica' => 'Vai na imagem do link, embaixo do nome. Até ' . Seo::FRASE_MAX . ' letras.',
                    'attrs' => ['maxlength' => Seo::FRASE_MAX, 'placeholder' => $seoPadrao['frase_compartilhamento'], 'autocomplete' => 'off']]) ?>
                <?= Campo::texto(['nome' => 'google_verificacao', 'rotulo' => 'Código de verificação do Google', 'valor' => $v('google_verificacao'), 'icone' => 'search', 'opcional' => true,
                    'erro' => $erros['google_verificacao'] ?? null, 'dica' => 'Do Google Search Console, opção "Tag HTML". Pode colar a etiqueta inteira: fica só o código.',
                    'attrs' => ['maxlength' => 300, 'autocomplete' => 'off', 'spellcheck' => 'false']]) ?>
                <div class="form-acoes">
                    <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('check', 'icon-sm') ?><span>Salvar textos do Google e do link</span></button>
                </div>
            </form>
        </div>
        <p class="texto-suave"><?= Icon::svg('info', 'icon-sm') ?>O WhatsApp e o Facebook guardam a prévia de cada link por alguns dias: links enviados antes podem demorar a mostrar a novidade. O mapa do site para o Google fica em <a href="/sitemap.xml" target="_blank" rel="noopener">/sitemap.xml</a>.</p>
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
