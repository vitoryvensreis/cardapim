<?php
$legalTitle = 'Exclusão de Dados';
$legalLead = 'Como pedir a exclusão dos seus dados no ' . marca() . ', em quanto tempo isso acontece e o que a lei manda guardar.';
?>
<div class="legal-document shell">
    <?php require __DIR__ . '/_header.php'; ?>
    <?php $legalResumo = [
        'Para pedir a exclusão, envie um e-mail para ' . $emailPrivacidadeHtml . ' a partir do contato cadastrado.',
        'Respondemos em até 15 dias.',
        'Alguns registros precisam ser guardados por lei, como notas, pagamentos e registros de acesso. Eles ficam bloqueados e são apagados quando o prazo termina.',
    ]; ?>
    <article class="legal-article" aria-label="Conteúdo da página de exclusão de dados">
        <?php require __DIR__ . '/_resumo.php'; ?>
        <section>
            <h2>1. Como pedir</h2>
            <p>Envie um e-mail para <?= $emailPrivacidadeHtml ?> com o assunto <strong>"Exclusão de dados"</strong> e informe:</p>
            <ul>
                <li>seu nome;</li>
                <li>o WhatsApp ou e-mail usado no <?= marca_html() ?>;</li>
                <li>se você é cliente de um restaurante, integrante de uma equipe ou responsável por um restaurante (e qual).</li>
            </ul>
            <p>Para ter certeza de que o pedido é seu, podemos enviar um código para o WhatsApp ou e-mail cadastrado. <strong>Nunca pedimos a sua senha.</strong></p>
        </section>
        <section>
            <h2>2. O que acontece com cada tipo de conta</h2>
            <ul>
                <li><strong>Cliente dos cardápios:</strong> apagamos ou anonimizamos nome, WhatsApp, CPF, endereços e as sessões abertas nos aparelhos. Nos pedidos antigos, o nome e o contato são substituídos para que não seja mais possível identificar você.</li>
                <li><strong>Integrante da equipe:</strong> o acesso é desativado e os dados pessoais são removidos. O histórico de ações no restaurante fica anonimizado. O responsável pelo restaurante também pode desativar o acesso no painel, em Equipe.</li>
                <li><strong>Restaurante:</strong> depois do encerramento da assinatura, o responsável pode pedir uma cópia dos dados antes da exclusão. Em seguida, cardápio, equipe, clientes vinculados e configurações são excluídos ou anonimizados.</li>
            </ul>
        </section>
        <section>
            <h2>3. O que precisa ser guardado</h2>
            <p>A lei exige que alguns dados sejam mantidos por um período, mesmo após o pedido de exclusão. Nesses casos, os dados ficam bloqueados, sem uso para outras finalidades, e são apagados ao fim do prazo:</p>
            <ul>
                <li><strong>Registros de acesso</strong> (data, hora e endereço IP): 6 meses, pelo Marco Civil da Internet.</li>
                <li><strong>Pedidos, pagamentos, faturas e dados fiscais:</strong> em geral 5 anos, pela legislação fiscal e contábil.</li>
                <li><strong>Dados necessários para defesa em processos</strong> ou para cumprir ordem de autoridade, enquanto forem necessários.</li>
            </ul>
        </section>
        <section>
            <h2>4. Dados de pedidos feitos em um restaurante</h2>
            <p>Os dados de um pedido pertencem ao restaurante onde ele foi feito. Quando recebemos o pedido de exclusão, avisamos o restaurante e cumprimos a parte que nos cabe. Você também pode falar diretamente com o restaurante.</p>
        </section>
        <section>
            <h2>5. Prazos</h2>
            <p>Confirmamos o recebimento e respondemos em até 15 dias. A exclusão nos sistemas em uso é feita nesse prazo. Cópias de segurança são substituídas automaticamente com o tempo, e os dados excluídos não são restaurados para uso.</p>
        </section>
        <section>
            <h2>6. Outros direitos</h2>
            <p>Você também pode pedir acesso, correção ou cópia dos seus dados pelo mesmo e-mail. Veja todos os direitos na <a href="/privacidade">Política de Privacidade</a>.</p>
        </section>
    </article>
    </div>
</div>
