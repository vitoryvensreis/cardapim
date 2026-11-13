<?php
$legalTitle = 'Política de Privacidade';
$legalLead = 'Quais dados o ' . marca() . ' usa, para quê, com quem compartilha e como você pode pedir acesso, correção ou exclusão.';
?>
<div class="legal-document shell">
    <?php require __DIR__ . '/_header.php'; ?>
    <?php $legalResumo = [
        'Usamos só os dados necessários para o sistema funcionar: contas, pedidos, entregas, pagamentos e segurança.',
        'Não vendemos dados e não usamos cookies de propaganda.',
        'Dados de pedidos são compartilhados apenas com quem precisa deles: o restaurante, o entregador e os serviços de pagamento e mensagens.',
        'Você pode pedir acesso, correção ou exclusão pelo e-mail ' . $emailPrivacidadeHtml . '.',
    ]; ?>
    <article class="legal-article" aria-label="Conteúdo da política de privacidade">
        <?php require __DIR__ . '/_resumo.php'; ?>
        <section>
            <h2>1. Quem somos</h2>
            <p>O <?= marca_html() ?> é um sistema online para restaurantes, bares e lanchonetes organizarem cardápio, pedidos, mesas, caixa, cozinha e delivery. Ele é oferecido pela <strong>Escotilha Produtora</strong>, CNPJ <strong>64.069.224/0001-32</strong>.</p>
            <p>Esta política vale para o site, o painel dos restaurantes, os cardápios online e os aplicativos instaláveis do <?= marca_html() ?>.</p>
        </section>
        <section>
            <h2>2. Quem decide sobre os seus dados</h2>
            <ul>
                <li><strong>O <?= marca_html() ?> (controlador)</strong> decide sobre os dados das contas de restaurantes e equipes, da assinatura e das faturas, da conta de cliente usada para entrar nos cardápios e dos registros de segurança.</li>
                <li><strong>O restaurante (controlador)</strong> decide sobre os dados dos pedidos feitos nele: o que foi pedido, entrega, pagamento e atendimento. Nesses casos o <?= marca_html() ?> trata os dados em nome do restaurante, como <strong>operador</strong>, seguindo as instruções dele.</li>
            </ul>
            <p>Mais detalhes sobre essa divisão estão na página <a href="/tratamento-de-dados">Tratamento de Dados</a>.</p>
        </section>
        <section>
            <h2>3. Quais dados usamos</h2>
            <ul>
                <li><strong>Equipe e responsáveis do restaurante:</strong> nome, usuário, e-mail, WhatsApp, CPF (opcional), cargo, senha guardada de forma criptografada (nunca em texto), configuração da verificação em duas etapas e registros de acesso (data, hora, endereço IP e navegador).</li>
                <li><strong>Restaurante:</strong> nome, CNPJ, endereço, telefone, logo, horários, cardápio, plano contratado e histórico de faturas.</li>
                <li><strong>Clientes dos cardápios:</strong> nome, WhatsApp (confirmado por código), endereço de entrega com localização no mapa, pedidos feitos e, apenas quando o pagamento é por Pix online, o CPF exigido pelo emissor do Pix.</li>
                <li><strong>Entregadores:</strong> dados da conta e a última localização enviada durante uma entrega, usada para o cliente acompanhar o pedido. Essa localização é apagada quando o entregador encerra o turno.</li>
                <li><strong>Pedidos recebidos do iFood:</strong> os dados que o iFood envia para preparar e entregar o pedido.</li>
                <li><strong>Atendimento:</strong> mensagens enviadas ao suporte e e-mails trocados com você.</li>
            </ul>
        </section>
        <section>
            <h2>4. Para que usamos e com qual base legal</h2>
            <ul>
                <li><strong>Prestar o serviço contratado</strong> (contas, cardápio, pedidos, entregas, pagamentos, avisos do pedido por WhatsApp): execução de contrato.</li>
                <li><strong>Cobrar a assinatura e emitir faturas:</strong> execução de contrato e obrigações legais e fiscais.</li>
                <li><strong>Manter a segurança</strong> (login, verificação em duas etapas, registros de acesso, prevenção de fraudes e de acessos indevidos): legítimo interesse e cumprimento do Marco Civil da Internet, que exige guardar registros de acesso.</li>
                <li><strong>Atendimento e suporte:</strong> execução de contrato e legítimo interesse.</li>
                <li><strong>Fontes externas do site:</strong> consentimento, dado no aviso de cookies.</li>
            </ul>
            <p>Não usamos seus dados para propaganda de terceiros e não tomamos decisões automatizadas que afetem seus direitos.</p>
        </section>
        <section>
            <h2>5. Com quem compartilhamos</h2>
            <p>Compartilhamos apenas o necessário para cada finalidade:</p>
            <ul>
                <li><strong>Restaurante e equipe:</strong> recebem os dados do pedido para preparar, cobrar e entregar.</li>
                <li><strong>Asaas:</strong> processa a cobrança da assinatura e os pagamentos por Pix dos pedidos (nome, CPF, valor).</li>
                <li><strong>WhatsApp, via WPPConnect:</strong> envio do código de acesso e dos avisos do pedido (número e texto da mensagem).</li>
                <li><strong>iFood:</strong> troca de status dos pedidos que vieram de lá, quando o restaurante conecta a integração.</li>
                <li><strong>OpenStreetMap e Nominatim:</strong> exibem o mapa e convertem endereço em localização. <strong>BrasilAPI e ViaCEP:</strong> preenchem o endereço pelo CEP e os dados do CNPJ.</li>
                <li><strong>Google Fonts:</strong> fontes do site, carregadas só com o seu consentimento.</li>
                <li><strong>Hospedagem e e-mail:</strong> servidor contratado pela Escotilha Produtora, onde ficam o banco de dados e o serviço de e-mail do sistema.</li>
                <li><strong>Autoridades:</strong> quando houver obrigação legal ou ordem judicial.</li>
            </ul>
            <p>Alguns desses serviços podem armazenar dados fora do Brasil. Nesses casos, a transferência segue as regras da LGPD.</p>
        </section>
        <section>
            <h2>6. Por quanto tempo guardamos</h2>
            <ul>
                <li><strong>Sessão da equipe:</strong> encerra após 2 horas sem uso ou 12 horas desde o login. Com "Lembrar de mim", até 30 dias.</li>
                <li><strong>Sessão do cliente no cardápio:</strong> até 60 dias no mesmo aparelho.</li>
                <li><strong>Localização do entregador:</strong> só enquanto o turno está aberto.</li>
                <li><strong>Registros de acesso:</strong> pelo menos 6 meses, como exige o Marco Civil da Internet.</li>
                <li><strong>Pedidos, pagamentos e faturas:</strong> pelo prazo exigido pela legislação fiscal e contábil, em geral 5 anos.</li>
                <li><strong>Demais dados da conta:</strong> enquanto a conta estiver ativa. Depois, são excluídos ou anonimizados, salvo obrigação legal.</li>
            </ul>
        </section>
        <section>
            <h2>7. Como protegemos</h2>
            <p>Conexão sempre criptografada (HTTPS), senhas guardadas com criptografia forte, verificação em duas etapas, dados de cada restaurante separados dos demais, permissões por cargo conferidas no servidor, chaves de integração criptografadas e registro das ações importantes. Nenhum sistema elimina todos os riscos. Se ocorrer um incidente que possa causar dano relevante, avisaremos os afetados e a Autoridade Nacional de Proteção de Dados (ANPD), como determina a lei.</p>
        </section>
        <section>
            <h2>8. Seus direitos</h2>
            <p>Pela LGPD, você pode pedir: confirmação de que tratamos seus dados, acesso a eles, correção, anonimização ou bloqueio do que for desnecessário, portabilidade, exclusão, informação sobre com quem compartilhamos e revogação do consentimento. Também pode reclamar à ANPD.</p>
            <p>Envie seu pedido para <?= $emailPrivacidadeHtml ?>. Para proteger você, podemos pedir uma confirmação de identidade (nunca pedimos senha). Respondemos em até 15 dias. Se o pedido for sobre um pedido feito em um restaurante, também avisamos o restaurante, que é quem decide sobre esses dados.</p>
            <p>Veja como funciona a exclusão na página <a href="/exclusao-de-dados">Exclusão de Dados</a>.</p>
        </section>
        <section>
            <h2>9. Crianças e adolescentes</h2>
            <p>O <?= marca_html() ?> é destinado a maiores de 18 anos. Não coletamos intencionalmente dados de menores de idade.</p>
        </section>
        <section>
            <h2>10. Mudanças nesta política</h2>
            <p>Quando esta política mudar, a data no topo será atualizada. Mudanças importantes serão avisadas no painel ou por e-mail antes de entrarem em vigor.</p>
        </section>
    </article>
    </div>
</div>
