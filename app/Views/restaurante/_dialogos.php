<?php
/**
 * Janelas do cardapio (abrem como "folha" de baixo para cima no celular e centralizadas no computador):
 * produto, sacola, entrar com WhatsApp e minha conta. Preenchidas pelo cardapio.js e cliente.js.
 */
use Cardapim\Core\Icon;
?>
<dialog class="cd-folha cd-folha-produto" data-dialogo-produto aria-labelledby="produto-nome">
    <form method="dialog" class="cd-folha-caixa" data-form-produto>
        <header class="cd-folha-topo">
            <span class="cd-folha-alca" aria-hidden="true"></span>
            <button class="cd-fechar" type="button" value="fechar" aria-label="Fechar" data-fechar><?= Icon::svg('x') ?></button>
        </header>
        <div class="cd-folha-corpo">
            <h2 id="produto-nome" data-produto-nome></h2>
            <p class="cd-folha-desc" data-produto-desc></p>
            <p class="cd-folha-preco" data-produto-preco></p>
            <label class="cd-obs">
                <span><?= Icon::svg('pencil', 'icon-sm') ?>Alguma observação? <small>(opcional)</small></span>
                <textarea name="obs" rows="2" maxlength="140" placeholder="Ex.: sem cebola, ponto da carne, pouco gelo" data-produto-obs></textarea>
                <small class="cd-obs-conta" data-obs-conta>0/140</small>
            </label>
        </div>
        <footer class="cd-folha-rodape">
            <div class="cd-stepper" role="group" aria-label="Quantidade">
                <button type="button" aria-label="Diminuir quantidade" data-qtd-menos><?= Icon::svg('minus') ?></button>
                <output data-qtd aria-live="polite">1</output>
                <button type="button" aria-label="Aumentar quantidade" data-qtd-mais><?= Icon::svg('plus') ?></button>
            </div>
            <button type="submit" class="btn btn-primary cd-adicionar" data-adicionar>
                <span data-adicionar-texto>Adicionar</span><span data-adicionar-total></span>
            </button>
        </footer>
    </form>
</dialog>

<dialog class="cd-folha cd-folha-sacola" data-dialogo-sacola aria-labelledby="sacola-titulo">
    <div class="cd-folha-caixa">
        <header class="cd-folha-topo cd-folha-topo-titulo">
            <span class="cd-folha-alca" aria-hidden="true"></span>
            <h2 id="sacola-titulo"><?= Icon::svg('shopping-bag') ?>Sua sacola</h2>
            <button class="cd-fechar" type="button" aria-label="Fechar sacola" data-fechar><?= Icon::svg('x') ?></button>
        </header>
        <div class="cd-folha-corpo" data-sacola-conteudo></div>
    </div>
</dialog>

<dialog class="cd-folha cd-folha-login" data-dialogo-login aria-labelledby="login-titulo">
    <div class="cd-folha-caixa">
        <header class="cd-folha-topo">
            <span class="cd-folha-alca" aria-hidden="true"></span>
            <button class="cd-voltar" type="button" aria-label="Voltar" data-login-voltar hidden><?= Icon::svg('arrow-left') ?></button>
            <button class="cd-fechar" type="button" aria-label="Fechar" data-fechar><?= Icon::svg('x') ?></button>
        </header>
        <div class="cd-folha-corpo">
            <span class="cd-login-icone" aria-hidden="true"><?= Icon::svg('message-circle', 'icon-lg') ?></span>

            <form class="cd-login-etapa" data-etapa="telefone" novalidate>
                <h2 id="login-titulo" data-login-titulo>Entre com seu WhatsApp</h2>
                <p class="cd-login-texto" data-login-motivo>Sem senha: enviamos um código para o seu WhatsApp.</p>
                <label class="cd-campo">
                    <span>Número do WhatsApp</span>
                    <span class="cd-campo-caixa"><span class="cd-ddi" aria-hidden="true">+55</span>
                        <input type="tel" name="telefone" inputmode="tel" autocomplete="tel-national" placeholder="(00) 00000-0000" maxlength="15" required data-telefone aria-describedby="erro-telefone">
                    </span>
                </label>
                <p class="cd-erro" id="erro-telefone" role="alert" data-erro hidden></p>
                <button type="submit" class="btn btn-primary btn-block cd-login-btn" data-enviar>
                    <?= Icon::svg('send', 'icon-sm') ?><span>Receber código no WhatsApp</span>
                </button>
                <p class="cd-login-nota" data-login-indisponivel hidden><?= Icon::svg('info', 'icon-sm') ?>O login pelo WhatsApp está indisponível no momento. Você pode montar a sacola normalmente.</p>
                <p class="cd-login-legal">Ao continuar, você concorda com os <a href="/termos-de-uso" target="_blank" rel="noopener">Termos de Uso</a> e a <a href="/privacidade" target="_blank" rel="noopener">Política de Privacidade</a>.</p>
            </form>

            <form class="cd-login-etapa" data-etapa="codigo" novalidate hidden>
                <h2>Digite o código</h2>
                <p class="cd-login-texto">Enviamos um código de 6 números para o WhatsApp <strong data-login-numero></strong>.</p>
                <label class="cd-campo">
                    <span>Código de verificação</span>
                    <input class="cd-codigo" type="text" name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required
                           aria-describedby="erro-codigo" placeholder="000000" data-codigo>
                </label>
                <p class="cd-erro" id="erro-codigo" role="alert" data-erro hidden></p>
                <button type="submit" class="btn btn-primary btn-block cd-login-btn" data-enviar><?= Icon::svg('check', 'icon-sm') ?><span>Confirmar</span></button>
                <div class="cd-login-links">
                    <button type="button" class="cd-link" data-reenviar disabled>Reenviar código <span data-reenviar-tempo></span></button>
                    <button type="button" class="cd-link" data-trocar-numero>Trocar número</button>
                </div>
            </form>

            <form class="cd-login-etapa" data-etapa="nome" novalidate hidden>
                <h2>Como podemos te chamar?</h2>
                <p class="cd-login-texto">É assim que o restaurante vai ver seu pedido.</p>
                <label class="cd-campo">
                    <span>Seu nome</span>
                    <input type="text" name="nome" autocomplete="name" autocapitalize="words" maxlength="80" required aria-describedby="erro-nome" placeholder="Ex.: Maria Silva" data-nome>
                </label>
                <p class="cd-erro" id="erro-nome" role="alert" data-erro hidden></p>
                <button type="submit" class="btn btn-primary btn-block cd-login-btn" data-enviar><?= Icon::svg('circle-check', 'icon-sm') ?><span>Criar minha conta</span></button>
            </form>
        </div>
    </div>
</dialog>

<dialog class="cd-folha cd-folha-conta" data-dialogo-conta aria-labelledby="conta-titulo">
    <div class="cd-folha-caixa">
        <header class="cd-folha-topo">
            <span class="cd-folha-alca" aria-hidden="true"></span>
            <button class="cd-fechar" type="button" aria-label="Fechar" data-fechar><?= Icon::svg('x') ?></button>
        </header>
        <div class="cd-folha-corpo cd-conta-corpo">
            <span class="cd-conta-avatar" aria-hidden="true" data-conta-iniciais></span>
            <h2 id="conta-titulo" data-conta-nome></h2>
            <p class="cd-login-texto cd-conta-linha"><?= Icon::svg('message-circle', 'icon-sm') ?><span data-conta-whatsapp></span></p>
            <p class="cd-login-nota"><?= Icon::svg('info', 'icon-sm') ?>Em breve: seus pedidos, endereços e dados aparecem aqui.</p>
            <button type="button" class="btn btn-outline btn-block" data-sair><?= Icon::svg('log-out', 'icon-sm') ?>Sair da conta</button>
        </div>
    </div>
</dialog>
