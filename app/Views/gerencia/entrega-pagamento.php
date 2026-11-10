<?php
/**
 * Aba Entrega e pagamento. Espera: $config, $enviado, $bairros, $faixas, $local, $precisaLocal, $asaasConectado,
 * $teste (null ou resultado do "Testar um endereco"), $erros (por formulario), $restaurante, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errosConfig = $erros['config'] ?? [];
$env = $enviado['config'] ?? null;
/** Valor marcado: o que foi enviado (erro) ou o salvo. */
$marcado = static fn (string $campo): bool => $env !== null ? ($env[$campo] ?? '') === '1' : (int) ($config[$campo] ?? 0) === 1;
$valor = static fn (string $campo): string => $env !== null ? (is_string($env[$campo] ?? null) ? $env[$campo] : '') : (string) ($config[$campo] ?? '');
$recebendo = (int) $config['aceita_entrega'] === 1 || (int) $config['aceita_retirada'] === 1;
$cidadePadrao = $local['cidade'] !== '' ? $local['cidade'] : '';
$mapa = static fn (float $lat, float $lon): string => sprintf('https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=17/%1$.6f/%2$.6f', $lat, $lon);

$formas = [
    'pag_pix_entrega' => ['Pix na entrega', 'qr-code', 'O cliente paga com Pix quando recebe ou retira.'],
    'pag_debito' => ['Cartão de débito', 'credit-card', 'Na maquininha, na entrega ou na retirada.'],
    'pag_credito' => ['Cartão de crédito', 'credit-card', 'Na maquininha, na entrega ou na retirada.'],
    'pag_dinheiro' => ['Dinheiro', 'banknote', 'O cliente pode pedir troco ao fazer o pedido.'],
];

/** Formulario de area (novo ou edicao). @param array<string,mixed>|null $area */
$formArea = static function (string $tipo, ?array $area, array $valores, array $errosForm) use ($e, $base, $csrf, $cidadePadrao): string {
    $prefixo = $area !== null ? 'area' . $area['id'] : $tipo;
    $v = static fn (string $c, string $padrao = ''): string => is_string($valores[$c] ?? null) ? $valores[$c] : $padrao;
    $acao = $area !== null ? $base . '/entrega-pagamento/areas/' . $area['id'] : $base . '/entrega-pagamento/areas';
    ob_start(); ?>
    <form method="post" action="<?= $e($acao) ?>" class="area-form" novalidate data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
        <div class="form-grade">
            <?php if ($tipo === 'bairro'): ?>
                <?= Campo::texto(['nome' => 'bairro', 'id' => $prefixo . '-bairro', 'rotulo' => 'Bairro', 'valor' => $v('bairro', (string) ($area['bairro'] ?? '')), 'icone' => 'map-pin',
                    'erro' => $errosForm['bairro'] ?? null, 'attrs' => ['maxlength' => 100, 'data-validate' => 'required', 'placeholder' => 'Exemplo: Aldeota']]) ?>
                <?= Campo::texto(['nome' => 'cidade', 'id' => $prefixo . '-cidade', 'rotulo' => 'Cidade', 'valor' => $v('cidade', (string) ($area['cidade'] ?? $cidadePadrao)), 'icone' => 'map-pin',
                    'erro' => $errosForm['cidade'] ?? null, 'attrs' => ['maxlength' => 100, 'data-validate' => 'required']]) ?>
            <?php else: ?>
                <?= Campo::texto(['nome' => 'raio_km', 'id' => $prefixo . '-raio', 'rotulo' => 'Entrega até', 'valor' => $v('raio_km', $area !== null ? Numero::decimalCampo((string) $area['raio_km']) : ''), 'icone' => 'map-pin',
                    'sufixo' => 'km', 'erro' => $errosForm['raio_km'] ?? null, 'dica' => 'Distância em linha reta do restaurante.', 'attrs' => ['inputmode' => 'decimal', 'maxlength' => 6, 'data-validate' => 'required', 'placeholder' => '3']]) ?>
            <?php endif; ?>
            <?= Campo::texto(['nome' => 'taxa', 'id' => $prefixo . '-taxa', 'rotulo' => 'Taxa de entrega', 'valor' => $v('taxa', $area !== null ? Numero::dinheiroCampo((string) $area['taxa']) : ''), 'icone' => 'banknote',
                'opcional' => true, 'erro' => $errosForm['taxa'] ?? null, 'dica' => 'Vazio = entrega grátis.', 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00']]) ?>
            <?= Campo::texto(['nome' => 'pedido_minimo', 'id' => $prefixo . '-minimo', 'rotulo' => 'Pedido mínimo', 'valor' => $v('pedido_minimo', $area !== null && $area['pedido_minimo'] !== null ? Numero::dinheiroCampo((string) $area['pedido_minimo']) : ''), 'icone' => 'shopping-bag',
                'opcional' => true, 'erro' => $errosForm['pedido_minimo'] ?? null, 'dica' => 'Vazio = sem mínimo.', 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00']]) ?>
        </div>
        <div class="form-acoes form-acoes-esquerda">
            <button class="btn btn-primary btn-sm" type="submit" data-submit-label="Salvando..."><?= Icon::svg($area !== null ? 'save' : 'plus', 'icon-sm') ?><?= $area !== null ? 'Salvar alterações' : ($tipo === 'bairro' ? 'Adicionar bairro' : 'Adicionar faixa') ?></button>
        </div>
    </form>
    <?php return (string) ob_get_clean();
};

/** Lista de areas. @param list<array<string,mixed>> $lista */
$listaAreas = static function (array $lista, string $tipo) use ($e, $base, $csrf, $erros, $enviado, $formArea): string {
    ob_start(); ?>
    <ul class="lista lista-areas">
        <li class="lista-cabecalho" aria-hidden="true"><span><?= $tipo === 'bairro' ? 'Bairro' : 'Distância' ?></span><span>Taxa</span><span>Pedido mínimo</span><span class="lista-cab-acoes">Ações</span></li>
        <?php foreach ($lista as $a):
            $pausada = (int) $a['ativo'] !== 1;
            $errosItem = $erros['area' . $a['id']] ?? [];
            $taxaCentavos = Numero::centavos((string) $a['taxa']); ?>
            <li class="lista-item<?= $pausada ? ' lista-item-pausado' : '' ?>" id="area-<?= (int) $a['id'] ?>">
                <div class="lista-principal">
                    <span class="lista-icone" aria-hidden="true"><?= Icon::svg($tipo === 'bairro' ? 'map-pin' : 'locate-fixed') ?></span>
                    <span class="lista-titulo">
                        <strong><?= $tipo === 'bairro' ? $e((string) $a['bairro']) : $e('Até ' . AreaEntrega::km((string) $a['raio_km'])) ?></strong>
                        <small><?= $tipo === 'bairro' ? $e((string) ($a['cidade'] ?? '')) : 'do restaurante' ?><?php if ($pausada): ?> · <span class="etiqueta etiqueta-neutra"><?= Icon::svg('pause') ?>Pausada</span><?php endif; ?></small>
                    </span>
                </div>
                <span class="lista-dado" data-rotulo="Taxa"><?= $taxaCentavos === 0 ? '<span class="etiqueta etiqueta-ok">Grátis</span>' : $e(Numero::reais((string) $a['taxa'])) ?></span>
                <span class="lista-dado" data-rotulo="Pedido mínimo"><?= $a['pedido_minimo'] !== null ? $e(Numero::reais((string) $a['pedido_minimo'])) : '<span class="texto-suave">Sem mínimo</span>' ?></span>
                <div class="lista-acoes">
                    <form method="post" action="<?= $e($base . '/entrega-pagamento/areas/' . $a['id'] . '/pausar') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-outline btn-sm" type="submit" title="<?= $pausada ? 'Voltar a entregar nesta área' : 'Parar de entregar nesta área por enquanto' ?>"><?= Icon::svg($pausada ? 'play' : 'pause', 'icon-sm') ?><?= $pausada ? 'Voltar a entregar' : 'Pausar' ?></button>
                    </form>
                    <form method="post" action="<?= $e($base . '/entrega-pagamento/areas/' . $a['id'] . '/excluir') ?>"
                          data-confirm="Os clientes desta área deixam de poder pedir entrega." data-confirm-titulo="Remover esta área?" data-confirm-acao="Remover" data-confirm-perigo="1">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="btn btn-ghost btn-sm area-remover" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Remover</button>
                    </form>
                </div>
                <details class="area-editar"<?= $errosItem !== [] ? ' open' : '' ?>>
                    <summary><?= Icon::svg('pencil', 'icon-sm') ?>Editar</summary>
                    <?= $formArea($tipo, $a, $enviado['area' . $a['id']] ?? [], $errosItem) ?>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php return (string) ob_get_clean();
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1>Entrega e pagamento</h1>
            <p>Como seus clientes recebem e pagam os pedidos do cardápio online.</p>
        </div>
        <div class="painel-acoes">
            <span class="etiqueta <?= $recebendo ? 'etiqueta-ok' : 'etiqueta-neutra' ?>"><?= Icon::svg($recebendo ? 'circle-check' : 'pause') ?><?= $recebendo ? 'Recebendo pedidos online' : 'Pedidos online desligados' ?></span>
            <a class="btn btn-outline btn-sm" href="/<?= $e((string) $restaurante['slug']) ?>" target="_blank" rel="noopener"><?= Icon::svg('arrow-up-right', 'icon-sm') ?>Ver meu cardápio</a>
        </div>
    </header>

    <div class="notice notice-info bloco-aviso" role="note">
        <?= Icon::svg('info') ?>
        <div>
            <strong>A tela para receber e aceitar os pedidos (cozinha e caixa) chega na próxima atualização.</strong>
            <p>Até lá, os pedidos feitos pelo cardápio ficam guardados, mas ainda não aparecem aqui no painel. Ligue os pedidos online só para testar.</p>
        </div>
    </div>

    <form method="post" action="<?= $e($base . '/entrega-pagamento') ?>" novalidate data-validate-form id="config">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?= Campo::resumoErros($errosConfig) ?>

        <section class="bloco" aria-labelledby="titulo-recebe">
            <div class="bloco-cabecalho">
                <h2 id="titulo-recebe"><?= Icon::svg('truck') ?>Como o cliente recebe o pedido</h2>
                <p>Marque uma ou as duas opções. Desmarcando as duas, o cardápio só mostra os produtos.</p>
            </div>
            <fieldset class="opcoes-modo opcoes-recebe">
                <legend class="sr-only">Modos de pedido</legend>
                <label class="opcao-modo">
                    <input type="checkbox" name="aceita_entrega" value="1"<?= $marcado('aceita_entrega') ? ' checked' : '' ?> data-modo="entrega">
                    <span><strong><?= Icon::svg('truck', 'icon-sm') ?>Entrega</strong><small>Você leva até o endereço do cliente, nas áreas definidas abaixo.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="checkbox" name="aceita_retirada" value="1"<?= $marcado('aceita_retirada') ? ' checked' : '' ?> data-modo="retirada">
                    <span><strong><?= Icon::svg('store', 'icon-sm') ?>Retirada no local</strong><small>O cliente busca o pedido no restaurante.</small></span>
                </label>
            </fieldset>
            <div class="form-grade tempos-grade">
                <div class="tempo-par" data-tempo="entrega">
                    <?= Campo::texto(['nome' => 'entrega_minutos_min', 'rotulo' => 'Entrega: de', 'valor' => $valor('entrega_minutos_min'), 'icone' => 'timer', 'sufixo' => 'min', 'opcional' => true,
                        'erro' => $errosConfig['entrega_minutos_min'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 3, 'placeholder' => '30']]) ?>
                    <?= Campo::texto(['nome' => 'entrega_minutos_max', 'rotulo' => 'até', 'valor' => $valor('entrega_minutos_max'), 'icone' => 'timer', 'sufixo' => 'min', 'opcional' => true,
                        'erro' => $errosConfig['entrega_minutos_max'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 3, 'placeholder' => '50']]) ?>
                </div>
                <div data-tempo="retirada">
                    <?= Campo::texto(['nome' => 'retirada_minutos', 'rotulo' => 'Pronto para retirar em', 'valor' => $valor('retirada_minutos'), 'icone' => 'timer', 'sufixo' => 'min', 'opcional' => true,
                        'erro' => $errosConfig['retirada_minutos'] ?? null, 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 3, 'placeholder' => '20']]) ?>
                </div>
            </div>
            <p class="texto-suave">O tempo aparece para o cliente antes de fazer o pedido. Deixe vazio se preferir não mostrar.</p>
        </section>

        <section class="bloco" aria-labelledby="titulo-pagamento" id="formas">
            <div class="bloco-cabecalho">
                <h2 id="titulo-pagamento"><?= Icon::svg('wallet') ?>Formas de pagamento</h2>
                <p>O cliente só vê as formas que estiverem marcadas.</p>
            </div>
            <?php if (isset($errosConfig['formas'])): ?>
                <p class="field-msg ficha-erro" id="campo-formas" role="alert"><?= $e($errosConfig['formas']) ?></p>
            <?php endif; ?>
            <h3 class="rotulo-grupo">Pago pelo app, antes de preparar</h3>
            <fieldset class="opcoes-modo">
                <legend class="sr-only">Pagamento pelo app</legend>
                <label class="opcao-modo">
                    <input type="checkbox" name="pag_pix_online" value="1"<?= $marcado('pag_pix_online') && $asaasConectado ? ' checked' : '' ?><?= $asaasConectado ? '' : ' disabled' ?>>
                    <span>
                        <strong><?= Icon::svg('smartphone', 'icon-sm') ?>Pix pelo app</strong>
                        <small>O cliente paga na hora e o dinheiro cai na sua conta Asaas. O pedido só chega para você depois de pago.</small>
                        <?php if (!$asaasConectado): ?>
                            <small class="opcao-aviso"><?= Icon::svg('plug-zap', 'icon-sm') ?>Precisa da sua conta Asaas conectada. <a href="<?= $e($base . '/integracoes/asaas') ?>">Conectar agora</a></small>
                        <?php endif; ?>
                    </span>
                </label>
            </fieldset>
            <h3 class="rotulo-grupo">Pago na entrega ou na retirada</h3>
            <fieldset class="opcoes-modo formas-grade">
                <legend class="sr-only">Pagamento na entrega ou na retirada</legend>
                <?php foreach ($formas as $campo => [$rotulo, $icone, $explica]): ?>
                    <label class="opcao-modo">
                        <input type="checkbox" name="<?= $e($campo) ?>" value="1"<?= $marcado($campo) ? ' checked' : '' ?>>
                        <span><strong><?= Icon::svg($icone, 'icon-sm') ?><?= $e($rotulo) ?></strong><small><?= $e($explica) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        </section>

        <div class="form-acoes">
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar entrega e pagamento</button>
        </div>
    </form>

    <section class="bloco" aria-labelledby="titulo-areas" id="areas">
        <div class="bloco-cabecalho">
            <h2 id="titulo-areas"><?= Icon::svg('map-pin') ?>Onde você entrega</h2>
            <p>Cadastre bairros, faixas de distância ou os dois. <strong>Se o bairro do cliente estiver na lista, vale a taxa do bairro.</strong> Se não estiver, vale a menor faixa de distância que alcança o endereço.</p>
        </div>
        <?php if ((int) $config['aceita_entrega'] !== 1): ?>
            <div class="notice notice-warning"><?= Icon::svg('info') ?><div>A entrega está desligada. Marque <strong>Entrega</strong> lá em cima e salve para os clientes poderem pedir.</div></div>
        <?php endif; ?>

        <div class="area-grupo" id="areas-bairro">
            <h3 class="area-grupo-titulo"><?= Icon::svg('map-pin', 'icon-sm') ?>Por bairro</h3>
            <?php if ($bairros === []): ?>
                <p class="texto-suave">Nenhum bairro ainda. Ótimo para quem cobra um valor por bairro.</p>
            <?php else: ?>
                <?= $listaAreas($bairros, 'bairro') ?>
            <?php endif; ?>
            <details class="area-nova"<?= isset($erros['bairro']) || $bairros === [] ? ' open' : '' ?>>
                <summary class="btn btn-outline btn-sm"><?= Icon::svg('plus', 'icon-sm') ?>Adicionar bairro</summary>
                <?= Campo::resumoErros($erros['bairro'] ?? [], 'bairro-') ?>
                <?= $formArea('bairro', null, $enviado['bairro'] ?? [], $erros['bairro'] ?? []) ?>
            </details>
        </div>

        <div class="area-grupo" id="areas-raio">
            <h3 class="area-grupo-titulo"><?= Icon::svg('locate-fixed', 'icon-sm') ?>Por distância</h3>
            <div class="local-estado" id="localizacao">
                <?php if ($local['latitude'] !== null): ?>
                    <p><span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Localização do restaurante definida</span>
                        <a class="link-acao" href="<?= $e($mapa($local['latitude'], $local['longitude'])) ?>" target="_blank" rel="noopener"><?= Icon::svg('map', 'icon-sm') ?>Conferir no mapa</a></p>
                <?php else: ?>
                    <div class="notice <?= $precisaLocal ? 'notice-warning' : 'notice-info' ?>"><?= Icon::svg('map-pin-off') ?><div><strong>Falta dizer onde fica o restaurante.</strong> Sem isso não dá para medir a distância até o cliente.</div></div>
                <?php endif; ?>
                <div class="local-acoes">
                    <form method="post" action="<?= $e($base . '/entrega-pagamento/localizacao') ?>" data-local-form>
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="origem" value="aparelho">
                        <input type="hidden" name="latitude" value="" data-local-lat>
                        <input type="hidden" name="longitude" value="" data-local-lon>
                        <button class="btn <?= $local['latitude'] !== null ? 'btn-outline' : 'btn-primary' ?> btn-sm" type="button" data-usar-local><?= Icon::svg('locate-fixed', 'icon-sm') ?><span data-usar-local-rotulo>Usar a localização deste aparelho</span></button>
                    </form>
                    <form method="post" action="<?= $e($base . '/entrega-pagamento/localizacao') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="origem" value="endereco">
                        <button class="btn btn-outline btn-sm" type="submit" data-submit-label="Buscando..."><?= Icon::svg('search', 'icon-sm') ?>Buscar pelo endereço do Perfil</button>
                    </form>
                    <?php if ($local['latitude'] !== null): ?>
                        <form method="post" action="<?= $e($base . '/entrega-pagamento/localizacao/remover') ?>"
                              data-confirm="As entregas por distância param de funcionar até você definir de novo." data-confirm-titulo="Remover a localização?" data-confirm-acao="Remover" data-confirm-perigo="1">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="link-acao link-perigo" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Remover</button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="field-msg ficha-erro" data-local-erro role="alert" hidden></p>
                <small class="texto-suave">Dica: abra esta tela no celular, dentro do restaurante, e toque em "Usar a localização deste aparelho". É o jeito mais preciso.</small>
            </div>
            <?php if ($faixas === []): ?>
                <p class="texto-suave">Nenhuma faixa ainda. Exemplo: até 3 km por R$ 5,00 e até 6 km por R$ 8,00.</p>
            <?php else: ?>
                <?= $listaAreas($faixas, 'raio') ?>
            <?php endif; ?>
            <details class="area-nova"<?= isset($erros['raio']) ? ' open' : '' ?>>
                <summary class="btn btn-outline btn-sm"><?= Icon::svg('plus', 'icon-sm') ?>Adicionar faixa de distância</summary>
                <?= Campo::resumoErros($erros['raio'] ?? [], 'raio-') ?>
                <?= $formArea('raio', null, $enviado['raio'] ?? [], $erros['raio'] ?? []) ?>
            </details>
        </div>
    </section>

    <section class="bloco" aria-labelledby="titulo-testar" id="testar">
        <div class="bloco-cabecalho">
            <h2 id="titulo-testar"><?= Icon::svg('search') ?>Testar um endereço</h2>
            <p>Veja o que o cliente vai ver: se você entrega e quanto custa.</p>
        </div>
        <form method="get" action="<?= $e($base . '/entrega-pagamento/testar') ?>#testar" class="form-grade teste-form">
            <?php $te = $teste['entrada'] ?? []; ?>
            <?= Campo::texto(['nome' => 'cep', 'id' => 'teste-cep', 'rotulo' => 'CEP', 'valor' => (string) ($te['cep'] ?? ''), 'icone' => 'map-pin', 'opcional' => true,
                'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'cep', 'placeholder' => '00000-000']]) ?>
            <?= Campo::texto(['nome' => 'numero', 'id' => 'teste-numero', 'rotulo' => 'Número', 'valor' => (string) ($te['numero'] ?? ''), 'icone' => 'map-pin', 'opcional' => true, 'attrs' => ['maxlength' => 20]]) ?>
            <?= Campo::texto(['nome' => 'bairro', 'id' => 'teste-bairro', 'rotulo' => 'Bairro', 'valor' => (string) ($te['bairro'] ?? ''), 'icone' => 'map-pin', 'opcional' => true, 'dica' => 'Sem CEP? Digite bairro e cidade.', 'attrs' => ['maxlength' => 100]]) ?>
            <?= Campo::texto(['nome' => 'cidade', 'id' => 'teste-cidade', 'rotulo' => 'Cidade', 'valor' => (string) ($te['cidade'] ?? $cidadePadrao), 'icone' => 'map-pin', 'opcional' => true, 'attrs' => ['maxlength' => 100]]) ?>
            <div class="form-acoes form-acoes-esquerda col-inteira">
                <button class="btn btn-outline" type="submit" data-submit-label="Testando..."><?= Icon::svg('search', 'icon-sm') ?>Testar endereço</button>
            </div>
        </form>
        <?php if ($teste !== null): ?>
            <?php if (($teste['resultado'] ?? null) === null): ?>
                <div class="notice notice-info" role="status"><?= Icon::svg('info') ?><div><?= $e((string) ($teste['aviso'] ?? '')) ?></div></div>
            <?php else: $res = $teste['resultado']; ?>
                <div class="notice <?= $res['entrega'] ? 'notice-success' : 'notice-warning' ?> teste-resultado" role="status">
                    <?= Icon::svg($res['entrega'] ? 'circle-check' : 'map-pin-off') ?>
                    <div>
                        <strong><?= $res['entrega'] ? 'Entrega disponível' : 'Fora da área de entrega' ?></strong>
                        <p><?= $e($res['mensagem']) ?></p>
                        <ul class="teste-detalhes">
                            <?php if (($teste['entrada']['logradouro'] ?? '') !== '' || ($teste['entrada']['bairro'] ?? '') !== ''): ?>
                                <li>Endereço: <?= $e(trim(implode(', ', array_filter([(string) $teste['entrada']['logradouro'], (string) $teste['entrada']['numero'], (string) $teste['entrada']['bairro'], (string) $teste['entrada']['cidade']])))) ?></li>
                            <?php endif; ?>
                            <?php if ($res['area'] !== ''): ?><li>Área: <?= $e($res['area']) ?></li><?php endif; ?>
                            <?php if ($res['distancia_km'] !== null): ?><li>Distância: <?= $e(AreaEntrega::km((string) $res['distancia_km'])) ?></li><?php endif; ?>
                            <?php if ($res['pedido_minimo'] !== null): ?><li>Pedido mínimo: <?= $e(Numero::reais($res['pedido_minimo'])) ?></li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
