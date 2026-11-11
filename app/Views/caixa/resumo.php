<?php
/**
 * Caixa: abrir (fechado) ou resumo do turno (aberto).
 * Espera: $caixa, $resumo, $movimentos, $pagamentos, $ultimo, $base, $csrf, $erros, $enviado.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$r = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$hora = static fn (string $d): string => date('H:i', (int) strtotime($d));
$dia = static fn (string $d): string => date('Y-m-d', (int) strtotime($d)) === date('Y-m-d') ? 'hoje, ' . date('H:i', (int) strtotime($d)) : date('d/m \à\s H:i', (int) strtotime($d));
$iconeForma = ['dinheiro' => 'banknote', 'debito' => 'credit-card', 'credito' => 'credit-card', 'pix' => 'qr-code', 'pix_online' => 'smartphone'];
?>
<div class="painel-page" data-regiao="pagina-resumo">
<?php if ($caixa === null): ?>
    <header class="painel-cabecalho">
        <div>
            <h1>Caixa</h1>
            <p>Abra o caixa para começar a receber: mesas, balcão e delivery.</p>
        </div>
        <span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Caixa fechado</span>
    </header>

    <section class="bloco caixa-abrir" aria-labelledby="titulo-abrir">
        <span class="caixa-abrir-icone" aria-hidden="true"><?= Icon::svg('lock-open', 'icon-lg') ?></span>
        <h2 id="titulo-abrir">Abrir o caixa</h2>
        <p>Conte o dinheiro que está na gaveta agora (o troco para começar o dia) e digite abaixo.</p>
        <form method="post" action="<?= $e($base . '/abrir') ?>" class="caixa-abrir-form" novalidate data-validate-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <?= Campo::texto(['nome' => 'valor_abertura', 'id' => 'valor-abertura', 'rotulo' => 'Troco inicial', 'valor' => $enviado['valor_abertura'] ?? '', 'icone' => 'banknote',
                'opcional' => true, 'erro' => $erros['valor_abertura'] ?? null, 'dica' => 'Sem dinheiro na gaveta? Deixe 0,00.',
                'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'money', 'placeholder' => '0,00', 'autofocus' => true]]) ?>
            <button class="btn btn-primary btn-lg btn-block" type="submit"><?= Icon::svg('lock-open', 'icon-sm') ?>Abrir caixa</button>
        </form>
    </section>

    <?php if ($ultimo !== null && $ultimo['status'] === 'fechado'): $dif = Numero::centavos((string) $ultimo['diferenca']); ?>
        <p class="caixa-ultimo">
            <?= Icon::svg('rotate-ccw-clock', 'icon-sm') ?>
            Último fechamento: <?= $e($dia((string) $ultimo['fechado_em'])) ?> por <?= $e((string) $ultimo['fechado_por']) ?>,
            <?= $dif === 0 ? 'sem diferença' : ($dif > 0 ? 'sobrou ' : 'faltou ') . $e($r(abs($dif))) ?>.
            <a href="<?= $e($base . '/historico/' . (int) $ultimo['id']) ?>">Ver detalhes</a>
        </p>
    <?php endif; ?>
<?php else: ?>
    <header class="painel-cabecalho">
        <div>
            <h1>Caixa</h1>
            <p>Aberto <?= $e($dia((string) $caixa['aberto_em'])) ?> por <?= $e((string) $caixa['aberto_por']) ?>.</p>
        </div>
        <div class="painel-acoes">
            <span class="etiqueta etiqueta-ok"><?= Icon::svg('circle-check') ?>Caixa aberto</span>
        </div>
    </header>

    <nav class="caixa-atalhos" aria-label="Ações rápidas do caixa">
        <a class="caixa-atalho" href="<?= $e($base . '/mesas') ?>"><?= Icon::svg('armchair') ?><span>Mesas</span></a>
        <form method="post" action="<?= $e($base . '/balcao') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="caixa-atalho" type="submit"><?= Icon::svg('store') ?><span>Venda no balcão</span></button>
        </form>
        <a class="caixa-atalho" href="<?= $e($base . '/delivery') ?>"><?= Icon::svg('bike') ?><span>Delivery</span></a>
        <a class="caixa-atalho" href="<?= $e($base . '/movimentos?tipo=entrada') ?>"><?= Icon::svg('arrow-down-to-line') ?><span>Entrada</span></a>
        <a class="caixa-atalho" href="<?= $e($base . '/movimentos?tipo=saida') ?>"><?= Icon::svg('arrow-up-from-line') ?><span>Saída</span></a>
        <a class="caixa-atalho caixa-atalho-fechar" href="<?= $e($base . '/fechar') ?>"><?= Icon::svg('lock') ?><span>Fechar caixa</span></a>
    </nav>

    <div class="indicadores caixa-indicadores">
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('receipt') ?>Vendas</span>
            <strong><?= $e($r($resumo['vendas'])) ?></strong>
            <small><?= (int) $resumo['pedidos'] ?> <?= $resumo['pedidos'] === 1 ? 'pedido recebido' : 'pedidos recebidos' ?></small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('banknote') ?>Dinheiro na gaveta</span>
            <strong><?= $e($r($resumo['esperado'])) ?></strong>
            <small>pela conta do sistema</small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('calculator') ?>Ticket médio</span>
            <strong><?= $e($r($resumo['ticket'])) ?></strong>
            <small>por pedido</small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('arrow-left-right') ?>Entradas e saídas</span>
            <strong class="caixa-mov"><span class="valor-mais">+<?= $e($r($resumo['entradas'])) ?></span> <span class="valor-menos">−<?= $e($r($resumo['saidas'])) ?></span></strong>
            <small><a href="<?= $e($base . '/movimentos') ?>">Ver todas</a></small>
        </div>
    </div>

    <div class="caixa-colunas">
        <section class="bloco" aria-labelledby="titulo-formas">
            <div class="bloco-cabecalho"><h2 id="titulo-formas"><?= Icon::svg('wallet') ?>Por forma de pagamento</h2></div>
            <dl class="caixa-formas">
                <div class="caixa-forma caixa-forma-base"><dt><?= Icon::svg('lock-open', 'icon-sm') ?>Troco inicial</dt><dd><?= $e($r($resumo['abertura'])) ?></dd></div>
                <?php foreach (CaixaService::FORMAS as $forma => $rotulo): ?>
                    <div class="caixa-forma"><dt><?= Icon::svg($iconeForma[$forma], 'icon-sm') ?><?= $e($rotulo) ?></dt><dd><?= $e($r($resumo['formas'][$forma])) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>

        <section class="bloco" aria-labelledby="titulo-recebidos">
            <div class="bloco-cabecalho"><h2 id="titulo-recebidos"><?= Icon::svg('receipt') ?>Últimos recebimentos</h2></div>
            <?php if ($pagamentos === []): ?>
                <p class="texto-suave">Nada recebido ainda neste caixa. Feche uma conta de mesa ou conclua um delivery e ele aparece aqui.</p>
            <?php else: ?>
                <ul class="caixa-linhas">
                    <?php foreach ($pagamentos as $pg): ?>
                        <li>
                            <span><strong>#<?= (int) $pg['numero'] ?></strong> <?= $pg['mesa_numero'] !== null ? 'Mesa ' . (int) $pg['mesa_numero'] : ($pg['tipo'] === 'balcao' ? 'Balcão' : ($pg['tipo'] === 'entrega' ? 'Entrega' : 'Retirada')) ?>
                                <small><?= $e(CaixaService::FORMAS[$pg['forma']] ?? (string) $pg['forma']) ?> · <?= $e($hora((string) $pg['criado_em'])) ?></small></span>
                            <span class="caixa-valor"><?= $e(Numero::reais((string) $pg['valor'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
</div>
