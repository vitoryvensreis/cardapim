<?php
/** Configuracoes da marca. Espera: $atuais, $valores, $erros, $base, $csrf. */
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
            <p>Nome, textos e logos da plataforma. O que você salvar aqui aparece em todo o sistema: site, painéis, cardápios, WhatsApp e e-mails.</p>
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
                        <img src="<?= $e($atual) ?>" alt="<?= $e($rotulo . ' atual') ?>" data-logo-previa>
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
</div>
