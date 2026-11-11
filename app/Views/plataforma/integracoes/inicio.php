<?php
/** Integracoes da plataforma: visao geral. Espera: $asaas, $whatsapp (integracoes), $whatsappDisponivel, $base. */
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
    </div>

    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Chaves protegidas</h2></div>
        <p class="texto-suave">Chaves e tokens ficam guardados criptografados e nunca aparecem inteiros na tela. Só a administração da plataforma pode conectar ou desconectar, e cada ação fica registrada nos logs.</p>
    </section>
</div>
