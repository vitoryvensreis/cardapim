<?php
/** Integração global Google Routes, apenas adm_master. Espera: $google, $erros, $csrf, $base. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = $base . '/integracoes/google-maps';
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="<?= $e($base . '/integracoes') ?>"><?= Icon::svg('arrow-left', 'icon-sm') ?>Integrações</a>
            <h1>Google Maps — distâncias de entrega</h1>
            <p>Uma chave da plataforma para todos os restaurantes. Quando ativado, a distância pelas ruas substitui o cálculo em linha reta das faixas de entrega.</p>
        </div>
        <span class="etiqueta <?= $google['ativo'] ? 'etiqueta-ok' : 'etiqueta-neutra' ?>">
            <?= Icon::svg($google['ativo'] ? 'circle-check' : 'power', 'icon-sm') ?>
            <?= $google['ativo'] ? 'Ativo' : 'Desligado' ?>
        </span>
    </header>

    <?php if (($erros['_geral'] ?? '') !== ''): ?>
        <div class="notice notice-warning" role="alert"><?= Icon::svg('circle-x') ?><div><strong>Não deu certo</strong><p><?= $e($erros['_geral']) ?></p></div></div>
    <?php endif; ?>

    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('map-pin') ?>Motor de distância ativo</h2></div>
        <p><strong><?= $google['ativo'] ? 'Google Routes API (pelas ruas)' : 'Haversine (linha reta)' ?></strong></p>
        <p class="texto-suave">As taxas por bairro continuam funcionando sem consultar o Google. O GPS e o mapa de rastreamento dos entregadores não mudam.</p>
        <?php if ($google['ativo']): ?>
            <div class="notice notice-info"><?= Icon::svg('info') ?><div>Distâncias pelas ruas podem mudar a faixa e o preço de entrega dos restaurantes. Se o Google estiver indisponível, o checkout informará que não conseguiu calcular, sem cobrar uma taxa estimada.</div></div>
        <?php endif; ?>
    </section>

    <?php if ($google['configurado']): ?>
        <section class="bloco">
            <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Chave protegida</h2></div>
            <div class="resumo-assinatura">
                <div class="resumo-linha"><span>Chave</span><strong><?= $e($google['chave_final']) ?></strong></div>
                <div class="resumo-linha"><span>Último teste válido</span><strong><?= $e($google['testado_em'] !== '' ? date('d/m/Y H:i', strtotime($google['testado_em'])) : 'Ainda não testada') ?></strong></div>
            </div>
            <div class="bloco-rodape">
                <form method="post" action="<?= $e($url . '/testar') ?>">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline" type="submit"><?= Icon::svg('circle-check', 'icon-sm') ?>Testar conexão</button>
                </form>
                <?php if ($google['ativo']): ?>
                    <form method="post" action="<?= $e($url . '/desativar') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-outline" type="submit"><?= Icon::svg('power', 'icon-sm') ?>Desligar Google e voltar ao cálculo anterior</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= $e($url . '/ativar') ?>"
                          data-confirm="Ao ativar, todos os restaurantes que cobram por distância passarão a usar quilômetros pelas ruas. As taxas podem mudar. Continuar?"
                          data-confirm-titulo="Ativar Google para toda a plataforma?" data-confirm-acao="Ativar Google">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-primary" type="submit" <?= $google['testado_em'] === '' ? 'disabled' : '' ?>>
                            <?= Icon::svg('power', 'icon-sm') ?>Ativar Google Maps
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="bloco">
        <div class="bloco-cabecalho">
            <h2><?= Icon::svg('key-round') ?><?= $google['configurado'] ? 'Substituir chave' : 'Cadastrar chave de API' ?></h2>
            <p>Google Cloud → APIs e serviços → Credenciais. Habilite somente a Routes API, faturamento e restrinja a chave ao IP da VPS.</p>
        </div>
        <form method="post" action="<?= $e($url) ?>" novalidate data-validate-form autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?= Campo::texto(['nome' => 'chave', 'rotulo' => 'Chave Google Maps Platform', 'tipo' => 'password', 'icone' => 'key-round',
                'senha' => true, 'opcional' => $google['configurado'], 'erro' => $erros['chave'] ?? null,
                'dica' => $google['configurado'] ? 'Em branco mantém a chave atual. Trocar desativa a integração até testar novamente.' : 'Chave guardada criptografada. Nunca aparecerá inteira novamente.',
                'attrs' => ['autocomplete' => 'new-password', 'spellcheck' => 'false', 'maxlength' => 256] + ($google['configurado'] ? [] : ['data-validate' => 'required'])]) ?>
            <div class="bloco-rodape">
                <button class="btn btn-primary" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar chave</button>
            </div>
        </form>
    </section>

    <?php if ($google['configurado']): ?>
        <section class="bloco">
            <div class="bloco-cabecalho"><h2><?= Icon::svg('unplug') ?>Remover integração</h2></div>
            <form method="post" action="<?= $e($url . '/remover') ?>"
                  data-confirm="Apagar a chave desligará a Routes API para todos os restaurantes. O cálculo atual em linha reta será restaurado."
                  data-confirm-titulo="Remover chave Google?" data-confirm-acao="Remover" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn-outline btn-sm link-perigo" type="submit"><?= Icon::svg('unplug', 'icon-sm') ?>Apagar chave</button>
            </form>
        </section>
    <?php endif; ?>

    <p class="texto-suave">A distância pelas ruas é usada apenas para determinar a faixa de preço, sem desenhar rotas no mapa OpenStreetMap. O cálculo de rastreamento do entregador continua independente.</p>
</div>
