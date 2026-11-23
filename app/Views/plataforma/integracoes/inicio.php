<?php
/** Integracoes da plataforma: visao geral. Espera: $asaas, $whatsapp (integracoes), $whatsappDisponivel, $sms (resumo), $aplicativos (resumos), $base. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cartoes = [
    'asaas' => ['Asaas', 'wallet', 'Recebe o pagamento das assinaturas dos restaurantes (Pix e boleto).', true, $asaas,
        $asaas['status'] !== 'desconectado'
            ? 'Conta: ' . (($asaas['dados']['conta_nome'] ?? '') ?: 'conectada') . ' · ' . ($asaas['ambiente'] === 'sandbox' ? 'Sandbox (testes)' : 'Produção') : ''],
    'whatsapp' => ['WhatsApp da plataforma', 'message-circle', 'Envia os códigos de verificação para os clientes entrarem só com o número de telefone.', $whatsappDisponivel, $whatsapp,
        !empty($whatsapp['dados']['numero']) ? 'Número: ' . Documento::formatarTelefone((string) $whatsapp['dados']['numero']) : ''],
];
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Integrações da plataforma</h1>
            <p>Contas da própria administração do <?= marca_html() ?>. Não são as contas dos restaurantes: cada restaurante conecta as suas no painel dele.</p>
        </div>
    </header>

    <div class="integracoes-grade">
        <?php foreach ($cartoes as $provedor => [$nome, $icone, $descricao, $disponivel, $i, $texto]):
            $statusIntegracao = $i['status'];
        ?>
            <article class="integracao-cartao is-<?= $e($provedor) ?>">
                <div class="integracao-topo">
                    <span class="integracao-icone" aria-hidden="true"><?= Icon::svg($icone) ?></span>
                    <?php if ($disponivel): ?>
                        <?php require dirname(__DIR__, 2) . '/gerencia/integracoes/_status.php'; ?>
                    <?php else: ?>
                        <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Não configurado</span>
                    <?php endif; ?>
                </div>
                <h2><?= $e($nome) ?></h2>
                <p><?= $e($descricao) ?></p>
                <?php if ($texto !== ''): ?><p class="integracao-detalhe"><?= $e($texto) ?></p><?php endif; ?>
                <?php if ($i['ultimo_erro']): ?><p class="texto-alerta"><?= $e((string) $i['ultimo_erro']) ?></p><?php endif; ?>
                <a class="btn <?= $statusIntegracao === 'desconectado' ? 'btn-primary' : 'btn-outline' ?> btn-sm btn-block" href="<?= $e($base . '/integracoes/' . $provedor) ?>">
                    <?= Icon::svg($statusIntegracao === 'desconectado' ? 'link' : 'settings', 'icon-sm') ?><?= $statusIntegracao === 'desconectado' ? ($disponivel ? 'Conectar' : 'Ver detalhes') : 'Gerenciar' ?>
                </a>
            </article>
        <?php endforeach; ?>

        <article class="integracao-cartao is-google-maps">
            <div class="integracao-topo">
                <span class="integracao-icone" aria-hidden="true"><?= Icon::svg('map-pin') ?></span>
                <span class="etiqueta <?= $google['ativo'] ? 'etiqueta-ok' : 'etiqueta-neutra' ?>">
                    <?= Icon::svg($google['ativo'] ? 'circle-check' : 'power') ?><?= $google['ativo'] ? 'Ativo' : ($google['configurado'] ? 'Desligado' : 'Não configurado') ?>
                </span>
            </div>
            <h2>Google Maps — Routes API</h2>
            <p>Distância pelas ruas para calcular as taxas de entrega de todos os restaurantes. Uma chave da plataforma.</p>
            <a class="btn <?= $google['configurado'] ? 'btn-outline' : 'btn-primary' ?> btn-sm btn-block" href="<?= $e($base . '/integracoes/google-maps') ?>">
                <?= Icon::svg($google['configurado'] ? 'settings' : 'key-round', 'icon-sm') ?><?= $google['configurado'] ? 'Gerenciar' : 'Configurar' ?>
            </a>
        </article>

        <?php $smsConfigurado = $sms['origem'] !== ''; ?>
        <article class="integracao-cartao is-sms">
            <div class="integracao-topo">
                <span class="integracao-icone" aria-hidden="true"><?= Icon::svg('smartphone') ?></span>
                <?php if ($smsConfigurado): ?>
                    <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Configurado</span>
                <?php else: ?>
                    <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Não configurado</span>
                <?php endif; ?>
            </div>
            <h2>SMS da plataforma</h2>
            <p>Reserva automática: se o WhatsApp falhar, o código de login do cliente vai por SMS pelo celular Android.</p>
            <?php if ($sms['origem'] === 'painel'): ?>
                <p class="integracao-detalhe">Usuário do app: <?= $e($sms['usuario']) ?></p>
            <?php elseif ($sms['origem'] === 'env'): ?>
                <p class="integracao-detalhe">Usando o arquivo de configuração do servidor.</p>
            <?php endif; ?>
            <a class="btn <?= $smsConfigurado ? 'btn-outline' : 'btn-primary' ?> btn-sm btn-block" href="<?= $e($base . '/integracoes/sms') ?>">
                <?= Icon::svg($smsConfigurado ? 'settings' : 'link', 'icon-sm') ?><?= $smsConfigurado ? 'Gerenciar' : 'Configurar' ?>
            </a>
        </article>
    </div>

    <section class="bloco" aria-labelledby="titulo-aplicativos">
        <div class="bloco-cabecalho">
            <h2 id="titulo-aplicativos"><?= Icon::svg('store') ?>Aplicativos nos marketplaces</h2>
            <p>O Client ID e o Client Secret do aplicativo que o <?= marca_html() ?> tem em cada marketplace. Os restaurantes só autorizam esse aplicativo no portal deles.</p>
        </div>
        <div class="integracoes-grade">
            <?php foreach ($aplicativos as $app):
                $configurado = $app['origem'] !== '';
            ?>
                <article class="integracao-cartao is-<?= $e($app['provedor']) ?>">
                    <div class="integracao-topo">
                        <span class="integracao-icone" aria-hidden="true"><?= Icon::svg($app['provedor'] === 'ifood' ? 'utensils' : 'bike') ?></span>
                        <?php if ($configurado): ?>
                            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Configurado</span>
                        <?php else: ?>
                            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Não configurado</span>
                        <?php endif; ?>
                    </div>
                    <h2><?= $e($app['nome']) ?></h2>
                    <p><?= $app['pronta'] ? 'Recebe os pedidos e sincroniza o cardápio e o estoque das lojas vinculadas.' : 'Integração em construção: as credenciais já podem ser guardadas.' ?></p>
                    <?php if ($app['origem'] === 'painel'): ?>
                        <p class="integracao-detalhe"><?= $e($app['rotulo_id']) ?>: <?= $e($app['client_id']) ?></p>
                    <?php elseif ($app['origem'] === 'env'): ?>
                        <p class="integracao-detalhe">Usando o arquivo de configuração do servidor.</p>
                    <?php endif; ?>
                    <a class="btn <?= $configurado ? 'btn-outline' : 'btn-primary' ?> btn-sm btn-block" href="<?= $e($base . '/integracoes/aplicativos/' . $app['provedor']) ?>">
                        <?= Icon::svg($configurado ? 'settings' : 'key-round', 'icon-sm') ?><?= $configurado ? 'Gerenciar' : 'Cadastrar credenciais' ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Chaves protegidas</h2></div>
        <p class="texto-suave">Chaves e tokens ficam guardados criptografados e nunca aparecem inteiros na tela. Só a administração da plataforma pode conectar ou desconectar, e cada ação fica registrada nos logs.</p>
    </section>
</div>
