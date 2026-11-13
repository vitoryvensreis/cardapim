<?php
$legalTitle = 'Política de Cookies';
$legalLead = 'Quais cookies e registros no navegador o ' . marca() . ' usa, para quê, por quanto tempo e como mudar a sua escolha.';
?>
<div class="legal-document shell">
    <?php require __DIR__ . '/_header.php'; ?>
    <?php $legalResumo = [
        'Usamos apenas cookies necessários para login, sacola e segurança.',
        'O único item opcional são as fontes do Google, que só carregam se você aceitar.',
        'Não usamos cookies de propaganda nem ferramentas de análise de comportamento.',
        'Você pode mudar a sua escolha a qualquer momento nesta página.',
    ]; ?>
    <article class="legal-article" aria-label="Conteúdo da política de cookies">
        <?php require __DIR__ . '/_resumo.php'; ?>
        <section>
            <h2>1. O que são cookies</h2>
            <p>Cookies são pequenos arquivos que o site guarda no seu navegador para lembrar informações, como o fato de você já ter entrado na sua conta. O armazenamento local do navegador funciona de forma parecida e também está descrito aqui.</p>
        </section>
        <section>
            <h2>2. Cookies necessários</h2>
            <p>Sem eles o sistema não funciona. Por isso não dependem de consentimento, como permite a LGPD.</p>
            <div class="legal-itens">
                <div class="legal-item">
                    <h3><code>cardapim_sessao</code><span class="legal-tag">Necessário</span></h3>
                    <p>Mantém a equipe do restaurante conectada e protege os formulários contra uso indevido. Expira após 2 horas sem uso ou 12 horas desde o login.</p>
                </div>
                <div class="legal-item">
                    <h3><code>cardapim_lembrar</code><span class="legal-tag">Necessário</span></h3>
                    <p>Só é criado se você marcar "Lembrar de mim" no login. Mantém o acesso no aparelho por até 30 dias.</p>
                </div>
                <div class="legal-item">
                    <h3><code>cardapim_cliente</code><span class="legal-tag">Necessário</span></h3>
                    <p>Mantém o cliente conectado nos cardápios depois de confirmar o WhatsApp. Dura até 60 dias no mesmo aparelho.</p>
                </div>
                <div class="legal-item">
                    <h3><code>cardapim_consentimento</code><span class="legal-tag">Necessário</span></h3>
                    <p>Guarda a sua escolha sobre cookies para não perguntar de novo. Dura 12 meses.</p>
                </div>
                <div class="legal-item">
                    <h3>Armazenamento local do navegador<span class="legal-tag">Necessário</span></h3>
                    <p>Guarda a sacola do cardápio (por até 2 dias), o último endereço de entrega usado e a preferência de som dos alertas de pedido. Também guardamos uma página para quando você estiver sem internet no aplicativo instalado. Esses dados ficam só no seu aparelho.</p>
                </div>
            </div>
        </section>
        <section>
            <h2>3. Itens opcionais</h2>
            <div class="legal-itens">
                <div class="legal-item">
                    <h3>Google Fonts<span class="legal-tag legal-tag--opcional">Opcional</span></h3>
                    <p>Carrega as fontes do visual do <?= marca_html() ?> a partir dos servidores do Google, que recebem dados técnicos da conexão, como endereço IP e navegador. Se você escolher "Só os necessários", o site usa as fontes do seu próprio aparelho e continua funcionando normalmente.</p>
                </div>
            </div>
        </section>
        <section>
            <h2>4. Serviços usados só quando você precisa deles</h2>
            <p>Quando você usa o mapa para informar o endereço de entrega, o mapa é carregado do OpenStreetMap. Ao pagar por Pix online, o pagamento é processado pelo Asaas. Esses serviços recebem dados técnicos da conexão para funcionar, mas o <?= marca_html() ?> não coloca cookies de propaganda por meio deles.</p>
        </section>
        <section>
            <h2>5. Como mudar a sua escolha</h2>
            <p>Use o botão abaixo para ver o aviso de novo e escolher outra opção. O link "Preferências de cookies" no rodapé do site faz o mesmo.</p>
            <p class="legal-acao"><button class="btn btn-wine btn-sm" type="button" data-consentimento-abrir>Alterar minhas preferências</button></p>
            <p>Você também pode apagar ou bloquear cookies nas configurações do navegador. Se bloquear os necessários, não será possível entrar na conta nem fazer pedidos.</p>
        </section>
        <section>
            <h2>6. Dúvidas</h2>
            <p>Escreva para <?= $emailPrivacidadeHtml ?>. Veja também a <a href="/privacidade">Política de Privacidade</a>.</p>
        </section>
    </article>
    </div>
</div>
