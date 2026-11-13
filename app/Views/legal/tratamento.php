<?php
$legalTitle = 'Tratamento de Dados';
$legalLead = 'Como o ' . marca() . ' e os restaurantes dividem as responsabilidades sobre os dados pessoais, de acordo com a LGPD.';
?>
<div class="legal-document shell">
    <?php require __DIR__ . '/_header.php'; ?>
    <?php $legalResumo = [
        'Nos dados dos pedidos, o restaurante decide e o ' . marca_html() . ' executa em nome dele.',
        'Nas contas, assinaturas e segurança, quem decide é o ' . marca_html() . '.',
        'Os dados de cada restaurante ficam separados e só a equipe autorizada tem acesso.',
    ]; ?>
    <article class="legal-article" aria-label="Conteúdo da página de tratamento de dados">
        <?php require __DIR__ . '/_resumo.php'; ?>
        <section>
            <h2>1. Quem é controlador e quem é operador</h2>
            <p>A LGPD chama de <strong>controlador</strong> quem decide por que e como os dados são usados, e de <strong>operador</strong> quem trata os dados em nome do controlador.</p>
            <ul>
                <li><strong>O restaurante é controlador</strong> dos dados dos pedidos, das comandas, das entregas, dos pagamentos dos seus clientes e do cadastro da sua equipe. O <?= marca_html() ?> é o <strong>operador</strong> desses dados.</li>
                <li><strong>O <?= marca_html() ?> (Escotilha Produtora, CNPJ 64.069.224/0001-32) é controlador</strong> dos dados da assinatura e das faturas, da conta de cliente usada para entrar nos cardápios, dos registros de acesso e de segurança e do atendimento do suporte.</li>
            </ul>
        </section>
        <section>
            <h2>2. Compromissos do <?= marca_html() ?> como operador</h2>
            <ul>
                <li>Usar os dados apenas para prestar o serviço contratado e conforme as configurações feitas pelo restaurante.</li>
                <li>Não vender, não usar para propaganda e não compartilhar os dados além do necessário para o funcionamento.</li>
                <li>Manter os dados de cada restaurante isolados, com acesso apenas para a equipe autorizada por ele.</li>
                <li>Ajudar o restaurante a atender pedidos de titulares, como acesso, correção e exclusão.</li>
                <li>Avisar o restaurante sem demora se houver incidente de segurança que envolva os dados dele.</li>
                <li>Ao fim do contrato, entregar uma cópia dos dados, se solicitada, e depois excluí-los, salvo o que a lei manda guardar.</li>
            </ul>
        </section>
        <section>
            <h2>3. Compromissos do restaurante como controlador</h2>
            <ul>
                <li>Coletar e usar os dados dos clientes apenas para atender os pedidos e cumprir a lei.</li>
                <li>Dar acesso ao painel somente a quem precisa, com o cargo adequado, e remover acessos de ex-funcionários.</li>
                <li>Responder aos clientes que pedirem informações sobre seus dados, com o nosso apoio.</li>
                <li>Não enviar mensagens de propaganda pelo WhatsApp conectado sem a autorização do cliente.</li>
            </ul>
        </section>
        <section>
            <h2>4. Serviços que tratam dados para nós (suboperadores)</h2>
            <ul>
                <li><strong>Hospedagem do servidor e e-mail do sistema:</strong> servidor contratado pela Escotilha Produtora.</li>
                <li><strong>Asaas:</strong> pagamentos por Pix e cobrança das assinaturas.</li>
                <li><strong>WPPConnect e WhatsApp:</strong> códigos de acesso e avisos de pedido.</li>
                <li><strong>iFood:</strong> pedidos recebidos pela integração, quando conectada pelo restaurante.</li>
                <li><strong>OpenStreetMap, Nominatim, BrasilAPI e ViaCEP:</strong> mapa, localização de endereços e consulta de CEP e CNPJ.</li>
            </ul>
            <p>Mudanças importantes nesta lista serão atualizadas nesta página.</p>
        </section>
        <section>
            <h2>5. Medidas de segurança</h2>
            <ul>
                <li>Conexão criptografada (HTTPS) em todas as páginas.</li>
                <li>Senhas guardadas com criptografia forte e verificação em duas etapas disponível.</li>
                <li>Permissões por cargo, sempre conferidas no servidor, e separação total entre restaurantes.</li>
                <li>Chaves das integrações guardadas de forma criptografada.</li>
                <li>Registro das ações importantes, como acessos, alterações e operações de caixa.</li>
                <li>Proteção contra os ataques mais comuns em sistemas web, como injeção de comandos e falsificação de formulários.</li>
            </ul>
        </section>
        <section>
            <h2>6. Incidentes</h2>
            <p>Se houver um incidente de segurança que possa causar risco ou dano relevante, o <?= marca_html() ?> avisa os restaurantes afetados e, quando for o caso, os titulares e a Autoridade Nacional de Proteção de Dados (ANPD), nos prazos definidos pela regulamentação.</p>
        </section>
        <section>
            <h2>7. Contato</h2>
            <p>Assuntos de proteção de dados: <?= $emailPrivacidadeHtml ?>. Veja também a <a href="/privacidade">Política de Privacidade</a>, os <a href="/termos-de-uso">Termos de Uso</a> e a página <a href="/exclusao-de-dados">Exclusão de Dados</a>.</p>
        </section>
    </article>
    </div>
</div>
