<?php
/**
 * Painel do entregador: localizacao em tempo real, entregas com ele (rota, contato, receber, entreguei) e historico.
 * Espera: $usuario, $csrf, $restaurante, $flash, $emRota, $hoje, $resumo, $posicao.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Pedidos\StatusPedido;
use Cardapim\Shared\Numero;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$slug = (string) $restaurante['slug'];
$minutos = static function (?string $desde): string {
    if (!$desde) {
        return '';
    }
    $m = max(0, (int) floor((time() - (int) strtotime($desde)) / 60));
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
?>
<div class="entregador" data-regiao="entregador">
    <?php if (!empty($flash['mensagem'])): ?>
        <div class="painel-flash painel-flash-<?= $e((string) $flash['tipo']) ?>" role="<?= ($flash['tipo'] ?? '') === 'erro' ? 'alert' : 'status' ?>" data-flash>
            <?= Icon::svg(($flash['tipo'] ?? '') === 'erro' ? 'circle-x' : 'circle-check') ?>
            <p><?= $e((string) $flash['mensagem']) ?></p>
        </div>
    <?php endif; ?>

    <header class="entregador-topo">
        <div>
            <p class="entregador-ola">Olá, <?= $e($usuario->nome) ?></p>
            <h1>Minhas entregas</h1>
        </div>
        <dl class="entregador-resumo">
            <div><dt>Hoje</dt><dd><strong><?= (int) $resumo['hoje']['qtd'] ?></strong> <?= $resumo['hoje']['qtd'] === 1 ? 'entrega' : 'entregas' ?><small><?= $e($r($resumo['hoje']['taxas'])) ?> em taxas</small></dd></div>
            <div><dt>7 dias</dt><dd><strong><?= (int) $resumo['semana']['qtd'] ?></strong> <?= $resumo['semana']['qtd'] === 1 ? 'entrega' : 'entregas' ?><small><?= $e($r($resumo['semana']['taxas'])) ?> em taxas</small></dd></div>
        </dl>
    </header>

    <section class="local-cartao" data-local data-local-ligado="<?= $posicao !== null ? '1' : '0' ?>" aria-labelledby="local-titulo">
        <span class="local-icone" aria-hidden="true"><?= Icon::svg('map-pin', 'icon-lg') ?></span>
        <div class="local-texto">
            <h2 id="local-titulo" data-local-titulo>Localização desligada</h2>
            <p data-local-status>Ligue para o cliente ver você chegando no mapa, como nos apps de corrida. Só aparece para o cliente do pedido que está com você.</p>
        </div>
        <button class="btn btn-primary btn-block local-botao" type="button" data-local-botao>
            <?= Icon::svg('locate-fixed', 'icon-sm') ?><span data-local-botao-texto>Compartilhar minha localização</span>
        </button>
        <p class="local-dica"><?= Icon::svg('info', 'icon-sm') ?>Deixe esta tela aberta durante as entregas. A tela fica acesa enquanto a localização estiver ligada.</p>
    </section>

    <section class="entregas" aria-labelledby="titulo-rota">
        <h2 class="entregas-titulo" id="titulo-rota"><?= Icon::svg('bike') ?>Com você agora <span class="fila-contagem"><?= count($emRota) ?></span></h2>
        <?php if ($emRota === []): ?>
            <div class="entregas-vazio">
                <?= Icon::svg('package') ?>
                <p><strong>Nenhuma entrega com você agora</strong>Quando o caixa despachar um pedido para você, ele aparece aqui na hora, com aviso.</p>
            </div>
        <?php else: ?>
            <ol class="entregas-lista">
                <?php foreach ($emRota as $p):
                    $endereco = trim((string) $p['entrega_logradouro'] . ', ' . (string) $p['entrega_numero'] . (!empty($p['entrega_complemento']) ? ' - ' . $p['entrega_complemento'] : ''));
                    $bairro = trim(implode(' · ', array_filter([(string) $p['entrega_bairro'], (string) $p['entrega_cidade']])));
                    $temCoord = $p['entrega_latitude'] !== null && $p['entrega_longitude'] !== null;
                    $destino = $temCoord ? $p['entrega_latitude'] . ',' . $p['entrega_longitude'] : $endereco . ', ' . $bairro;
                    $google = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode($destino);
                    $waze = $temCoord ? 'https://waze.com/ul?navigate=yes&ll=' . rawurlencode($destino) : 'https://waze.com/ul?navigate=yes&q=' . rawurlencode($destino);
                    $total = Numero::centavos((string) $p['total']);
                    $pago = $p['pagamento_status'] === 'pago';
                    $troco = $p['forma_pagamento'] === 'dinheiro' && $p['troco_para'] !== null ? Numero::centavos((string) $p['troco_para']) - $total : 0;
                    $tel = (string) ($p['cliente_whatsapp'] ?? ''); ?>
                    <li class="entrega">
                        <div class="entrega-topo">
                            <strong>Pedido #<?= (int) $p['numero'] ?></strong>
                            <span><?= Icon::svg('clock', 'icon-sm') ?>saiu há <?= $e($minutos((string) $p['despachado_em'])) ?></span>
                        </div>
                        <p class="entrega-cliente"><?= Icon::svg('user', 'icon-sm') ?><?= $e((string) ($p['cliente_nome'] ?? 'Cliente')) ?></p>
                        <p class="entrega-endereco"><?= $e($endereco) ?><small><?= $e($bairro) ?></small>
                            <?php if (!empty($p['entrega_referencia'])): ?><small class="entrega-ref"><?= Icon::svg('info', 'icon-sm') ?><?= $e((string) $p['entrega_referencia']) ?></small><?php endif; ?></p>

                        <div class="entrega-receber <?= $pago ? 'is-pago' : '' ?>">
                            <?php if ($pago): ?>
                                <?= Icon::svg('circle-check', 'icon-sm') ?><span><strong>Já pago pelo app.</strong> Não cobre nada.</span>
                            <?php else: ?>
                                <?= Icon::svg('wallet', 'icon-sm') ?><span>Receber <strong><?= $e($r($total)) ?></strong> · <?= $e(StatusPedido::FORMAS[$p['forma_pagamento']] ?? '') ?>
                                <?php if ($troco > 0): ?><br>Levar troco: <strong><?= $e($r($troco)) ?></strong> (para <?= $e(Numero::reais((string) $p['troco_para'])) ?>)<?php endif; ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($p['observacao'])): ?><p class="entrega-obs"><?= Icon::svg('pencil', 'icon-sm') ?><?= $e((string) $p['observacao']) ?></p><?php endif; ?>
                        <details class="entrega-itens">
                            <summary><?= count($p['itens']) ?> <?= count($p['itens']) === 1 ? 'item' : 'itens' ?> no pedido</summary>
                            <ul><?php foreach ($p['itens'] as $i): ?><li><?= (int) $i['quantidade'] ?>x <?= $e((string) $i['nome']) ?><?= $i['observacao'] ? ' (' . $e((string) $i['observacao']) . ')' : '' ?></li><?php endforeach; ?></ul>
                        </details>

                        <div class="entrega-acoes">
                            <a class="btn btn-outline" href="<?= $e($google) ?>" target="_blank" rel="noopener"><?= Icon::svg('map', 'icon-sm') ?>Google Maps</a>
                            <a class="btn btn-outline" href="<?= $e($waze) ?>" target="_blank" rel="noopener"><?= Icon::svg('send', 'icon-sm') ?>Waze</a>
                            <?php if (strlen($tel) >= 10): ?>
                                <a class="btn btn-outline" href="tel:+55<?= $e($tel) ?>"><?= Icon::svg('phone', 'icon-sm') ?>Ligar</a>
                                <a class="btn btn-outline" href="https://wa.me/55<?= $e($tel) ?>" target="_blank" rel="noopener" aria-label="WhatsApp do cliente <?= $e(Documento::formatarTelefone($tel)) ?>"><?= Icon::svg('message-circle', 'icon-sm') ?>WhatsApp</a>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="/<?= $e(rawurlencode($slug)) ?>/entregas/<?= (int) $p['id'] ?>/entregue"
                              data-confirm="<?= $pago ? 'Confirme que o cliente recebeu o pedido.' : 'Confirme que entregou e recebeu ' . $e($r($total)) . '.' ?>" data-confirm-titulo="Entregou o pedido #<?= (int) $p['numero'] ?>?" data-confirm-acao="Entreguei">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <button class="btn btn-primary btn-lg btn-block" type="submit"><?= Icon::svg('circle-check', 'icon-sm') ?>Entreguei</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="entregas" aria-labelledby="titulo-hoje">
        <h2 class="entregas-titulo" id="titulo-hoje"><?= Icon::svg('circle-check') ?>Feitas hoje <span class="fila-contagem is-neutra"><?= count($hoje) ?></span></h2>
        <?php if ($hoje === []): ?>
            <p class="texto-suave">Nenhuma entrega concluída hoje ainda.</p>
        <?php else: ?>
            <ul class="feitas">
                <?php foreach ($hoje as $p): ?>
                    <li>
                        <span><strong>#<?= (int) $p['numero'] ?></strong> <?= $e((string) ($p['cliente_nome'] ?? '')) ?><small><?= $e((string) ($p['entrega_bairro'] ?? '')) ?> · entregue às <?= $e(date('H:i', (int) strtotime((string) $p['concluido_em']))) ?></small></span>
                        <span class="feitas-valor"><?= $e(Numero::reais((string) $p['taxa_entrega'])) ?><small>taxa</small></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
