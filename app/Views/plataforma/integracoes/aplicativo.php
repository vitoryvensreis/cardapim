<?php
/**
 * Credenciais do aplicativo da plataforma em um marketplace (iFood, 99Food).
 * Espera: $app (AplicativosParceirosService::resumo), $erros, $valores, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = static fn (string $v): string => $v !== '' ? date('d/m/Y H:i', (int) strtotime($v)) : '-';
$salvoNoPainel = $app['origem'] === 'painel';
$acao = $base . '/integracoes/aplicativos/' . $app['provedor'];
$erroGeral = $erros['_geral'] ?? null;
unset($erros['_geral']);
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>Aplicativo <?= $e($app['nome']) ?></h1>
            <p>Credenciais do aplicativo que o <?= marca_html() ?> tem no <?= $e($app['nome']) ?>. Valem para todos os restaurantes.</p>
        </div>
        <?php if ($app['origem'] !== ''): ?>
            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Configurado</span>
        <?php else: ?>
            <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Não configurado</span>
        <?php endif; ?>
    </header>

    <?php if ($erroGeral !== null && $erros === []): ?>
        <div class="notice notice-warning erros-form" role="alert" tabindex="-1" data-erros-form><?= Icon::svg('circle-x') ?><div><strong>Não deu certo</strong><p><?= $e($erroGeral) ?></p></div></div>
    <?php endif; ?>

    <?php if (!$app['pronta']): ?>
        <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Integração em construção</strong><p>As credenciais ficam guardadas e serão usadas quando a integração com a <?= $e($app['nome']) ?> for ativada.</p></div></div>
    <?php endif; ?>

    <?php if ($salvoNoPainel): ?>
        <section class="bloco" aria-labelledby="titulo-atual">
            <div class="bloco-cabecalho"><h2 id="titulo-atual"><?= Icon::svg('key-round') ?>Credenciais salvas</h2></div>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span><?= $e($app['rotulo_id']) ?></span><strong class="chave-curta"><?= $e($app['client_id']) ?></strong></div>
                <div class="resumo-linha"><span>Client Secret</span><strong class="chave-curta"><?= $e($app['segredo_final']) ?></strong></div>
                <div class="resumo-linha"><span>Salvas em</span><strong><?= $e($data($app['salvo_em'])) ?><?= $app['salvo_por'] !== '' ? ' por ' . $e($app['salvo_por']) : '' ?></strong></div>
                <?php if ($app['pronta']): ?>
                    <div class="resumo-linha"><span>Lojas vinculadas</span><strong><?= (int) $app['lojas'] ?></strong></div>
                <?php endif; ?>
            </div>
        </section>
    <?php elseif ($app['origem'] === 'env'): ?>
        <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Usando o arquivo de configuração do servidor</strong><p>Hoje as credenciais vêm de <?= $e($app['env_id']) ?> e <?= $e($app['env_secret']) ?> no servidor. Se você salvar aqui, o painel passa a valer no lugar delas.</p></div></div>
    <?php else: ?>
        <section class="bloco" aria-labelledby="titulo-como">
            <div class="bloco-cabecalho"><h2 id="titulo-como"><?= Icon::svg('info') ?>Onde encontrar</h2></div>
            <ol class="passos-2fa">
                <li><strong>Entre no <?= $e($app['portal']) ?></strong>.</li>
                <li><strong>Abra o aplicativo do <?= marca_html() ?></strong> e copie o <?= $e($app['rotulo_id']) ?> e o Client Secret.</li>
                <li><strong>Cole aqui embaixo</strong>. Ficam guardados criptografados e o segredo não aparece mais inteiro.</li>
            </ol>
        </section>
    <?php endif; ?>

    <section class="bloco" aria-labelledby="titulo-form">
        <div class="bloco-cabecalho">
            <h2 id="titulo-form"><?= Icon::svg($salvoNoPainel ? 'settings' : 'key-round') ?><?= $salvoNoPainel ? 'Trocar credenciais' : 'Cadastrar credenciais' ?></h2>
            <?php if ($salvoNoPainel && $app['pronta'] && $app['lojas'] > 0): ?>
                <p>Trocar o <?= $e($app['rotulo_id']) ?> desliga as <?= (int) $app['lojas'] ?> lojas vinculadas: elas vão precisar vincular de novo.</p>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= $e($acao) ?>" novalidate data-validate-form autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?= Campo::texto(['nome' => 'client_id', 'rotulo' => $app['rotulo_id'], 'valor' => $valores['client_id'] ?? '', 'icone' => 'hash', 'erro' => $erros['client_id'] ?? null,
                'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 200, 'data-validate' => 'required']]) ?>
            <?= Campo::texto(['nome' => 'client_secret', 'rotulo' => 'Client Secret', 'tipo' => 'password', 'icone' => 'key-round', 'erro' => $erros['client_secret'] ?? null, 'senha' => true,
                'opcional' => $salvoNoPainel,
                'dica' => $salvoNoPainel ? 'Deixe em branco para manter o que já está salvo.' : 'Fica guardado criptografado. Depois de salvar, aparecem só os 4 últimos caracteres.',
                'attrs' => ['autocomplete' => 'new-password', 'spellcheck' => 'false', 'maxlength' => 400] + ($salvoNoPainel ? [] : ['data-validate' => 'required'])]) ?>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar credenciais</button>
            </div>
        </form>
    </section>

    <?php if ($salvoNoPainel): ?>
        <section class="bloco" aria-labelledby="titulo-remover">
            <div class="bloco-cabecalho">
                <h2 id="titulo-remover"><?= Icon::svg('unplug') ?>Apagar credenciais</h2>
                <p>Use se o aplicativo foi excluído no portal do <?= $e($app['nome']) ?> ou se as credenciais vazaram.</p>
            </div>
            <form method="post" action="<?= $e($acao . '/remover') ?>"
                  data-confirm="As credenciais serão apagadas do painel.<?= $app['pronta'] ? ' Sem elas, os pedidos do ' . $e($app['nome']) . ' param de chegar.' : '' ?>" data-confirm-titulo="Apagar credenciais do <?= $e($app['nome']) ?>?" data-confirm-acao="Apagar" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('unplug', 'icon-sm') ?>Apagar credenciais</button>
            </form>
        </section>
    <?php endif; ?>
</div>
