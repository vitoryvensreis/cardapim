<?php
/** Configurar aplicativo autenticador. Espera: $segredo, $uri, $erro, $base, $csrf. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Seguranca\Totp;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/minha-conta#duas-etapas') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Minha conta</a>
            <h1>Ligar aplicativo autenticador</h1>
            <p>Leva menos de um minuto. Siga os 3 passos.</p>
        </div>
    </header>

    <?php if (is_string($erro) && $erro !== ''): ?>
        <div class="notice notice-warning erros-form" role="alert"><?= Icon::svg('circle-x') ?><div><strong>Não deu certo</strong><p><?= $e($erro) ?></p></div></div>
    <?php endif; ?>

    <section class="bloco">
        <ol class="passos-2fa">
            <li>
                <strong>Instale um aplicativo autenticador no celular.</strong>
                <p class="texto-suave">Google Authenticator ou Microsoft Authenticator. São gratuitos na Play Store e na App Store.</p>
            </li>
            <li>
                <strong>No aplicativo, toque em "+" e escaneie este código:</strong>
                <div class="qr-codigo" data-qr="<?= $e($uri) ?>" role="img" aria-label="QR Code para adicionar o Cardapim ao aplicativo autenticador">
                    <span class="texto-suave">Carregando QR Code...</span>
                </div>
                <p class="texto-suave">Não consegue escanear? Escolha "inserir chave" no aplicativo e digite:</p>
                <p><span class="chave-manual" data-chave><?= $e(Totp::agrupar($segredo)) ?></span>
                    <button class="link-acao" type="button" data-copiar="<?= $e($segredo) ?>"><?= Icon::svg('copy', 'icon-sm') ?>Copiar chave</button>
                    <span class="texto-suave" data-copiado role="status"></span></p>
            </li>
            <li>
                <strong>Digite o código de 6 números que aparece no aplicativo:</strong>
                <form method="post" action="<?= $e($base . '/minha-conta/dois-fatores/app') ?>" class="form-codigo" novalidate data-validate-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <div class="field codigo-6" data-field>
                        <label class="sr-only" for="codigo-app">Código de 6 números</label>
                        <div class="field-control sem-icone">
                            <input id="codigo-app" name="codigo" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" placeholder="000000" required data-validate="required" aria-describedby="codigo-app-msg">
                        </div>
                        <p class="field-msg" id="codigo-app-msg"></p>
                    </div>
                    <button class="btn btn-primary" type="submit" data-submit-label="Conferindo..."><?= Icon::svg('shield-check', 'icon-sm') ?>Ligar verificação</button>
                </form>
            </li>
        </ol>
    </section>
</div>
