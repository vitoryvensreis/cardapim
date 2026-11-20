<?php
/** Integracoes: visao geral. Espera: $integracoes (provedor => dados), $ifoodDisponivel, $whatsappDisponivel, $agentConectado, $agentDownload, $base. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cartoes = [
    'ifood' => ['iFood', 'shopping-bag', 'Receba os pedidos do iFood direto no ' . marca() . '.', $ifoodDisponivel],
    'whatsapp' => ['WhatsApp', 'message-circle', 'Conecte o WhatsApp do restaurante para avisar clientes e atender.', $whatsappDisponivel],
    'asaas' => ['Asaas', 'wallet', 'Receba o Pix dos pedidos online direto na sua conta Asaas.', true],
];
$detalhe = static function (string $provedor, array $i) use ($e): string {
    $d = $i['dados'];
    return match (true) {
        $provedor === 'ifood' && isset($d['loja_nome']) => 'Loja: ' . $e((string) $d['loja_nome']),
        $provedor === 'whatsapp' && !empty($d['numero']) => 'Número: ' . $e(Documento::formatarTelefone((string) $d['numero'])),
        $provedor === 'asaas' && $i['status'] !== 'desconectado' => 'Conta: ' . $e((string) (($d['conta_nome'] ?? '') ?: 'conectada')) . ' · ' . ($i['ambiente'] === 'sandbox' ? 'Sandbox (testes)' : 'Produção'),
        default => '',
    };
};
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Integrações</h1>
            <p>Conecte o <?= marca_html() ?> aos serviços que você já usa. Cada restaurante conecta as próprias contas.</p>
        </div>
    </header>

    <div class="integracoes-grade">
        <?php foreach ($cartoes as $provedor => [$nome, $icone, $descricao, $disponivel]):
            $i = $integracoes[$provedor];
            $statusIntegracao = $i['status'];
            $texto = $detalhe($provedor, $i);
        ?>
            <article class="integracao-cartao is-<?= $e($provedor) ?>">
                <div class="integracao-topo">
                    <span class="integracao-icone" aria-hidden="true"><?= Icon::svg($icone) ?></span>
                    <?php if ($disponivel): ?>
                        <?php require __DIR__ . '/_status.php'; ?>
                    <?php else: ?>
                        <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Em breve</span>
                    <?php endif; ?>
                </div>
                <h2><?= $e($nome) ?></h2>
                <p><?= $e($descricao) ?></p>
                <?php if ($texto !== ''): ?><p class="integracao-detalhe"><?= $texto ?></p><?php endif; ?>
                <?php if ($i['ultimo_erro']): ?><p class="texto-alerta"><?= $e((string) $i['ultimo_erro']) ?></p><?php endif; ?>
                <a class="btn <?= $i['status'] === 'desconectado' ? 'btn-primary' : 'btn-outline' ?> btn-sm btn-block" href="<?= $e($base . '/integracoes/' . $provedor) ?>">
                    <?= Icon::svg($i['status'] === 'desconectado' ? 'link' : 'settings', 'icon-sm') ?><?= $i['status'] === 'desconectado' ? ($disponivel ? 'Conectar' : 'Ver detalhes') : 'Gerenciar' ?>
                </a>
            </article>
        <?php endforeach; ?>
        <article class="integracao-cartao is-agent">
            <div class="integracao-topo">
                <span class="integracao-icone" aria-hidden="true"><?= Icon::svg('monitor') ?></span>
                <?php if (!empty($agentConectado)): ?>
                    <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Conectado</span>
                <?php else: ?>
                    <span class="etiqueta etiqueta-neutra"><?= Icon::svg('monitor') ?>Para o computador</span>
                <?php endif; ?>
            </div>
            <h2><?= marca_html() ?> Agent</h2>
            <p>Programa para o computador do caixa (Windows): avisa pedido novo com som até alguém receber, imprime em uma ou várias impressoras (Balcão, Cozinha...) e lê a balança.</p>
            <p class="integracao-detalhe">Instala em 1 minuto e abre sozinho quando o computador liga, já com o painel no navegador.</p>
            <a class="btn btn-primary btn-sm btn-block" href="<?= $e((string) $agentDownload) ?>" rel="noopener" download><?= Icon::svg('download', 'icon-sm') ?>Baixar o Agent para Windows</a>
            <a class="btn btn-outline btn-sm btn-block" href="<?= $e($base . '/integracoes/impressoras') ?>"><?= Icon::svg('printer', 'icon-sm') ?>Impressoras e pontos de impressão</a>
        </article>
        <article class="integracao-cartao is-importar">
            <div class="integracao-topo">
                <span class="integracao-icone" aria-hidden="true"><?= Icon::svg('file-spreadsheet') ?></span>
                <span class="etiqueta etiqueta-neutra"><?= Icon::svg('upload') ?>Planilha</span>
            </div>
            <h2>Importar cardápio</h2>
            <p>Cadastre muitos produtos de uma vez com uma planilha do Excel ou do Google Planilhas, com categorias e complementos.</p>
            <p class="integracao-detalhe">Você confere tudo antes de importar.</p>
            <a class="btn btn-primary btn-sm btn-block" href="<?= $e($base . '/integracoes/importar-cardapio') ?>"><?= Icon::svg('file-spreadsheet', 'icon-sm') ?>Importar planilha</a>
        </article>
        <article class="integracao-cartao is-99food is-em-breve">
            <div class="integracao-topo">
                <span class="integracao-icone" aria-hidden="true"><?= Icon::svg('utensils') ?></span>
                <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Em breve</span>
            </div>
            <h2>99Food</h2>
            <p>Receba os pedidos da 99Food direto no <?= marca_html() ?>, junto com os pedidos do cardápio e do iFood.</p>
            <p>Estamos preparando esta conexão. Avisaremos aqui quando estiver liberada.</p>
            <button class="btn btn-outline btn-sm btn-block" type="button" disabled><?= Icon::svg('clock', 'icon-sm') ?>Disponível em breve</button>
        </article>
    </div>

    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Suas chaves ficam protegidas</h2></div>
        <p class="texto-suave">Chaves e tokens são guardados criptografados e nunca aparecem inteiros na tela. Só o gerente do restaurante pode conectar ou desconectar, e cada ação fica registrada no histórico.</p>
    </section>
</div>
