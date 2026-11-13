<?php
$legalTitle = 'Termos de Uso';
$legalLead = 'As regras para usar o ' . marca() . ': o que oferecemos, o que esperamos de cada parte e como funcionam planos, cobrança e cancelamento.';
?>
<div class="legal-document shell">
    <?php require __DIR__ . '/_header.php'; ?>
    <?php $legalResumo = [
        'O restaurante assina um plano e usa o sistema para cardápio, pedidos, mesas, caixa, cozinha e delivery.',
        'O restaurante é responsável pelos produtos, preços, entregas e pelo atendimento aos seus clientes.',
        'A assinatura pode ser cancelada pelo painel a qualquer momento, sem multa. O acesso continua até o fim do período já pago.',
        'Ao usar o sistema, você concorda com estes termos e com a Política de Privacidade.',
    ]; ?>
    <article class="legal-article" aria-label="Conteúdo dos termos de uso">
        <?php require __DIR__ . '/_resumo.php'; ?>
        <section>
            <h2>1. Quem oferece o serviço</h2>
            <p>O <?= marca_html() ?> é oferecido pela <strong>Escotilha Produtora</strong>, CNPJ <strong>64.069.224/0001-32</strong>. Ao criar uma conta, entrar no sistema ou fazer um pedido por um cardápio do <?= marca_html() ?>, você concorda com estes Termos de Uso e com a <a href="/privacidade">Política de Privacidade</a>.</p>
        </section>
        <section>
            <h2>2. O que o <?= marca_html() ?> oferece</h2>
            <p>Um sistema online para restaurantes, bares e lanchonetes, com cardápio digital, pedidos online, mesas e comandas, caixa, tela da cozinha, controle de estoque, entregas, financeiro e integrações opcionais (pagamentos por Pix via Asaas, avisos por WhatsApp e pedidos do iFood). Os recursos disponíveis dependem do plano contratado.</p>
            <p>O <?= marca_html() ?> é uma ferramenta de gestão. <strong>Não vendemos, não preparamos e não entregamos os produtos</strong> oferecidos pelos restaurantes.</p>
        </section>
        <section>
            <h2>3. Conta e acessos</h2>
            <ul>
                <li>Para criar uma conta é preciso ter 18 anos ou mais e informar dados verdadeiros.</li>
                <li>O responsável pelo restaurante cria os acessos da equipe e define o que cada pessoa pode fazer. Ele deve remover os acessos de quem não trabalha mais no restaurante.</li>
                <li>Cada pessoa deve usar o próprio acesso e manter a senha em segredo. Recomendamos ligar a verificação em duas etapas.</li>
                <li>Se perceber uso indevido da sua conta, troque a senha e avise o suporte imediatamente.</li>
            </ul>
        </section>
        <section>
            <h2>4. Planos, cobrança e atraso</h2>
            <ul>
                <li>Os planos, valores e o que cada um inclui aparecem no site e no painel antes da contratação.</li>
                <li>A cobrança é recorrente (mensal ou anual, conforme o plano) e pode ser paga por Pix ou boleto, processados pelo Asaas. As faturas ficam disponíveis no painel.</li>
                <li>Se uma fatura atrasar além do prazo de tolerância informado no painel, o acesso do restaurante pode ser pausado até o pagamento. Os dados não são apagados por causa do atraso.</li>
                <li>Mudanças de preço serão avisadas com antecedência e só valem a partir do período seguinte.</li>
            </ul>
        </section>
        <section>
            <h2>5. Cancelamento</h2>
            <p>O restaurante pode cancelar a assinatura a qualquer momento no menu <strong>Assinatura</strong> do painel, sem multa. Não haverá novas cobranças e o acesso continua até o fim do período já pago. Até essa data, é possível desfazer o cancelamento.</p>
            <p>Valores de períodos já usados não são devolvidos, salvo nos casos previstos no Código de Defesa do Consumidor ou em erro de cobrança, que será corrigido.</p>
        </section>
        <section>
            <h2>6. Responsabilidades do restaurante</h2>
            <ul>
                <li>Manter cardápio, preços, horários, taxas e áreas de entrega corretos.</li>
                <li>Cumprir as leis aplicáveis ao seu negócio, como as normas sanitárias, fiscais e do Código de Defesa do Consumidor.</li>
                <li>Atender os clientes, entregar os pedidos e resolver trocas, cancelamentos e reembolsos dos seus pedidos.</li>
                <li>Usar os dados dos clientes só para atender os pedidos e de acordo com a LGPD.</li>
                <li>Manter as contas conectadas (Asaas, WhatsApp e iFood) em seu nome e seguir as regras desses serviços.</li>
            </ul>
        </section>
        <section>
            <h2>7. Clientes que fazem pedidos</h2>
            <p>O pedido feito por um cardápio do <?= marca_html() ?> é uma compra com o restaurante. Dúvidas sobre produtos, prazos, entrega, troca ou reembolso devem ser resolvidas diretamente com o restaurante, que aparece no cardápio e no acompanhamento do pedido.</p>
        </section>
        <section>
            <h2>8. O que não é permitido</h2>
            <ul>
                <li>Tentar acessar dados de outros restaurantes ou contas que não são suas.</li>
                <li>Usar o sistema para fraudes, golpes ou venda de produtos proibidos.</li>
                <li>Enviar mensagens em massa não solicitadas (spam) pelo WhatsApp conectado.</li>
                <li>Copiar, revender ou tentar desmontar o sistema, ou sobrecarregá-lo de propósito.</li>
            </ul>
            <p>Nesses casos podemos suspender a conta, de forma proporcional à gravidade, e avisar as autoridades quando necessário.</p>
        </section>
        <section>
            <h2>9. Funcionamento e limites de responsabilidade</h2>
            <p>Trabalhamos para manter o sistema disponível o tempo todo. Ainda assim podem ocorrer manutenções e falhas, inclusive de serviços de terceiros como internet, WhatsApp, Asaas e iFood. Manutenções programadas serão avisadas com antecedência sempre que possível.</p>
            <p>Respondemos pelos danos causados por falhas do próprio <?= marca_html() ?>, nos limites da lei. Não respondemos por produtos e entregas dos restaurantes nem por falhas de serviços de terceiros fora do nosso controle. Nada nestes termos limita direitos garantidos pelo Código de Defesa do Consumidor.</p>
        </section>
        <section>
            <h2>10. Propriedade intelectual</h2>
            <p>O sistema, a marca e o visual do <?= marca_html() ?> pertencem à Escotilha Produtora. O cardápio, fotos, logo e demais conteúdos enviados pelo restaurante continuam sendo do restaurante, que nos autoriza a exibi-los para prestar o serviço.</p>
        </section>
        <section>
            <h2>11. Encerramento da conta</h2>
            <p>Ao encerrar a conta, o restaurante pode pedir uma cópia dos seus dados. Depois, os dados são excluídos ou anonimizados, exceto o que a lei manda guardar, como explicado na página <a href="/exclusao-de-dados">Exclusão de Dados</a>.</p>
        </section>
        <section>
            <h2>12. Mudanças e lei aplicável</h2>
            <p>Estes termos podem ser atualizados. Mudanças importantes serão avisadas no painel ou por e-mail antes de valer. Aplicam-se as leis brasileiras. Fica eleito o foro do domicílio do consumidor, quando a relação for de consumo.</p>
            <p>Atendimento: pelo menu Suporte, dentro do painel, ou pelo e-mail <?= $emailPrivacidadeHtml ?> para assuntos de dados pessoais.</p>
        </section>
    </article>
    </div>
</div>
