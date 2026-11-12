<?php
/**
 * Visao geral do restaurante: o dia em numeros, o que esta acontecendo agora, alertas e vendas recentes.
 * Espera: $vg (VisaoGeralService::painel), $usuario, $restaurante, $base.
 * O conteudo de [data-visao-geral] e trocado sozinho pelo visao-geral.js quando chega pedido ou muda o caixa.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$reais = static fn (int $c): string => Numero::reais(CaixaService::dec($c));
$reaisCurto = static fn (int $c): string => $c >= 100000 ? 'R$ ' . number_format($c / 100000, 1, ',', '.') . ' mil' : 'R$ ' . number_format(intdiv($c, 100), 0, ',', '.');
$slugUrl = '/' . rawurlencode((string) $restaurante['slug']);
$h = $vg['hoje'];
$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$diasCurtos = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$agora = $vg['agora'];
$dataExtenso = $diasSemana[(int) $agora->format('w')] . ', ' . (int) $agora->format('j') . ' de ' . $meses[(int) $agora->format('n') - 1];
$horaAtual = (int) $agora->format('G');
$saudacaoTexto = $horaAtual < 12 ? 'Bom dia' : ($horaAtual < 18 ? 'Boa tarde' : 'Boa noite');
$etapas = [
    'recebido' => ['Novos', 'bell', 'is-novo'],
    'em_preparo' => ['Em preparo', 'chef-hat', ''],
    'pronto' => ['Prontos', 'package-check', ''],
    'saiu_entrega' => ['Saíram', 'bike', ''],
    'aguardando_pagamento' => ['Esperando Pix', 'clock', ''],
];
$minutos = static function (string $quando): string {
    $m = max(0, (int) floor((time() - (int) strtotime($quando)) / 60));
    return $m < 60 ? 'há ' . $m . ' min' : 'há ' . intdiv($m, 60) . 'h' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
};
$estoque = $vg['estoque'];
$alertasEstoque = [...$estoque['emFalta'], ...$estoque['baixos']];
$contas = $vg['contas'];
$mesas = $vg['mesas'];
$caixa = $vg['caixa'];
$rc = $vg['resumoCaixa'];
$semVendasSemana = array_sum(array_column($vg['semana'], 'total')) === 0;
?>
<div class="painel-page" data-visao-geral data-visao-url="<?= $e($base) ?>">
    <header class="painel-cabecalho">
        <div>
            <p class="painel-hello"><?= $e($saudacaoTexto) ?>, <?= $e($usuario->nome) ?>!</p>
            <h1>Visão geral</h1>
            <p><?= $e(ucfirst($dataExtenso)) ?> · atualizado às <span data-visao-hora><?= $e($agora->format('H:i')) ?></span></p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($base . '/financeiro') ?>"><?= Icon::svg('banknote', 'icon-sm') ?>Financeiro</a>
            <a class="btn btn-primary btn-sm" href="<?= $e($slugUrl . '/caixa') ?>"><?= Icon::svg('monitor', 'icon-sm') ?><?= $caixa === null ? 'Abrir o caixa' : 'Ir para o caixa' ?></a>
        </div>
    </header>

    <?php if ($contas['vencidas'] > 0 || $estoque['emFalta'] !== []): ?>
        <div class="vg-alertas">
            <?php if ($estoque['emFalta'] !== []): ?>
                <a class="vg-alerta is-alerta" href="<?= $e($base . '/estoque') ?>">
                    <?= Icon::svg('package-x') ?>
                    <span><strong><?= count($estoque['emFalta']) === 1 ? '1 insumo acabou' : count($estoque['emFalta']) . ' insumos acabaram' ?></strong>
                        <small><?= $e(implode(', ', array_slice(array_map(static fn (array $i): string => (string) $i['nome'], $estoque['emFalta']), 0, 3))) ?><?= count($estoque['emFalta']) > 3 ? '...' : '' ?></small></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                </a>
            <?php endif; ?>
            <?php if ($contas['vencidas'] > 0): ?>
                <a class="vg-alerta is-alerta" href="<?= $e($base . '/financeiro/contas?situacao=vencidas') ?>">
                    <?= Icon::svg('calendar-clock') ?>
                    <span><strong><?= $contas['vencidas'] === 1 ? '1 conta vencida' : $contas['vencidas'] . ' contas vencidas' ?></strong>
                        <small><?= $e($reais($contas['vencidas_valor'])) ?> para pagar</small></span>
                    <?= Icon::svg('chevron-right', 'icon-sm') ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="indicadores">
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('receipt') ?>Vendas hoje</span>
            <strong><?= $e($reais($h['vendas'])) ?></strong>
            <?php if ($h['variacao'] === null): ?>
                <small><?= $h['ontem'] === 0 && $h['vendas'] > 0 ? 'ontem, até esta hora, não teve venda' : 'pedidos concluídos hoje' ?></small>
            <?php else: ?>
                <small class="vg-variacao <?= $h['variacao'] >= 0 ? 'is-sobe' : 'is-desce' ?>"><?= Icon::svg($h['variacao'] >= 0 ? 'trending-up' : 'trending-down', 'icon-sm') ?><?= $h['variacao'] >= 0 ? '+' : '' ?><?= (int) $h['variacao'] ?>% em relação a ontem até esta hora (<?= $e($reais($h['ontem'])) ?>)</small>
            <?php endif; ?>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('shopping-bag') ?>Pedidos hoje</span>
            <strong><?= (int) $h['pedidos'] ?></strong>
            <small><?= $vg['filaTotal'] > 0 ? ($vg['filaTotal'] === 1 ? '+1 em andamento agora' : '+' . (int) $vg['filaTotal'] . ' em andamento agora') : 'concluídos' ?></small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('scale') ?>Ticket médio</span>
            <strong><?= $e($reais($h['ticket'])) ?></strong>
            <small>valor médio por pedido hoje</small>
        </div>
        <a class="indicador vg-link" href="<?= $e($slugUrl . '/caixa/mesas') ?>">
            <span class="indicador-topo"><?= Icon::svg('armchair') ?>Mesas ocupadas</span>
            <strong><?= (int) $mesas['ocupadas'] ?><span class="vg-de"> de <?= (int) $mesas['ativas'] ?></span></strong>
            <small><?= $mesas['ocupadas'] > 0 ? $e($reais($mesas['aberto'])) . ' em comandas abertas' : 'nenhuma comanda aberta' ?></small>
        </a>
    </div>

    <div class="blocos-duplos vg-agora">
        <section class="bloco" aria-labelledby="titulo-andamento">
            <div class="bloco-cabecalho">
                <h2 id="titulo-andamento"><?= Icon::svg('bike') ?>Pedidos em andamento</h2>
                <a class="btn-icone" href="<?= $e($slugUrl . '/caixa/delivery') ?>"><?= Icon::svg('arrow-up-right', 'icon-sm') ?><span>Ver todos</span></a>
            </div>
            <?php if ($vg['filaTotal'] === 0): ?>
                <div class="lista-vazia">
                    <?= Icon::svg('circle-check') ?>
                    <strong>Nenhum pedido esperando agora</strong>
                    <p>Pedidos do cardápio online e do WhatsApp aparecem aqui na hora em que chegam.</p>
                </div>
            <?php else: ?>
                <ul class="vg-etapas" aria-label="Pedidos por etapa">
                    <?php foreach ($etapas as $st => [$etRotulo, $etIcone, $etClasse]): if ($vg['porStatus'][$st] === 0 && $st === 'aguardando_pagamento') { continue; } ?>
                        <li class="<?= $vg['porStatus'][$st] > 0 ? 'tem ' . $etClasse : '' ?>"><?= Icon::svg($etIcone, 'icon-sm') ?><strong><?= (int) $vg['porStatus'][$st] ?></strong><span><?= $e($etRotulo) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <ul class="vg-pedidos">
                    <?php foreach ($vg['fila'] as $p): [$etRotulo, $etIcone] = $etapas[$p['status']] ?? ['', 'circle']; ?>
                        <li>
                            <a href="<?= $e($slugUrl . '/caixa/delivery/' . (int) $p['id']) ?>">
                                <strong>#<?= (int) $p['numero'] ?></strong>
                                <span class="vg-pedido-info"><?= $e(mb_strimwidth((string) ($p['cliente_nome'] ?? 'Cliente'), 0, 26, '…')) ?><small><?= $p['tipo'] === 'entrega' ? 'Entrega' : 'Retirada' ?> · <?= $e($minutos((string) $p['criado_em'])) ?></small></span>
                                <span class="etiqueta <?= $p['status'] === 'recebido' ? 'etiqueta-atencao' : 'etiqueta-neutra' ?>"><?= Icon::svg($etIcone) ?><?= $e($etRotulo) ?></span>
                                <span class="vg-pedido-valor"><?= $e(Numero::reais((string) $p['total'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-caixa">
            <div class="bloco-cabecalho">
                <h2 id="titulo-caixa"><?= Icon::svg($caixa === null ? 'lock' : 'lock-open') ?>Caixa</h2>
                <?php if ($caixa !== null): ?><span class="etiqueta etiqueta-ok"><?= Icon::svg('check') ?>Aberto</span><?php else: ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Fechado</span><?php endif; ?>
            </div>
            <?php if ($caixa === null): ?>
                <div class="lista-vazia">
                    <?= Icon::svg('monitor') ?>
                    <strong>O caixa está fechado</strong>
                    <p>Abra o caixa para receber pagamentos no balcão e nas mesas.</p>
                    <p class="lista-vazia-acao"><a class="btn btn-primary" href="<?= $e($slugUrl . '/caixa') ?>"><?= Icon::svg('lock-open', 'icon-sm') ?>Abrir o caixa</a></p>
                </div>
            <?php else: ?>
                <dl class="vg-caixa">
                    <div><dt>Aberto desde</dt><dd><?= $e(date('d/m H:i', (int) strtotime((string) $caixa['aberto_em']))) ?><small><?= $e(trim((string) $caixa['aberto_por'])) ?></small></dd></div>
                    <div><dt>Vendas neste caixa</dt><dd><?= $e($reais((int) $rc['vendas'])) ?><small><?= (int) $rc['pedidos'] === 1 ? '1 pedido' : (int) $rc['pedidos'] . ' pedidos' ?></small></dd></div>
                    <div><dt>Dinheiro na gaveta</dt><dd><?= $e($reais((int) $rc['esperado'])) ?><small>esperado agora</small></dd></div>
                    <div><dt>Pix e cartões</dt><dd><?= $e($reais((int) $rc['vendas'] - (int) $rc['formas']['dinheiro'])) ?><small>não ficam na gaveta</small></dd></div>
                </dl>
                <div class="bloco-rodape">
                    <a class="btn btn-outline btn-sm" href="<?= $e($slugUrl . '/caixa/movimentos') ?>"><?= Icon::svg('arrow-right-left', 'icon-sm') ?>Entradas e saídas</a>
                    <a class="btn btn-wine btn-sm" href="<?= $e($slugUrl . '/caixa') ?>"><?= Icon::svg('monitor', 'icon-sm') ?>Ir para o caixa</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="blocos-duplos">
        <section class="bloco" aria-labelledby="titulo-semana">
            <div class="bloco-cabecalho">
                <h2 id="titulo-semana"><?= Icon::svg('chart-no-axes-column') ?>Vendas dos últimos 7 dias</h2>
                <a class="btn-icone" href="<?= $e($base . '/financeiro?periodo=7dias') ?>"><?= Icon::svg('arrow-up-right', 'icon-sm') ?><span>Detalhes</span></a>
            </div>
            <?php if ($semVendasSemana): ?>
                <div class="lista-vazia"><?= Icon::svg('chart-no-axes-column') ?><strong>Nenhuma venda nos últimos 7 dias</strong><p>O gráfico aparece quando os primeiros pedidos forem concluídos.</p></div>
            <?php else:
                $barras = array_map(static fn (array $d): array => [
                    'rotulo' => $d['dia'] === $agora->format('Y-m-d') ? 'Hoje' : $diasCurtos[(int) date('w', (int) strtotime($d['dia']))] . ' ' . date('d', (int) strtotime($d['dia'])),
                    'valor' => $d['total'],
                    'dica' => date('d/m', (int) strtotime($d['dia'])) . ': ' . $reais($d['total']) . ' em ' . $d['pedidos'] . ($d['pedidos'] === 1 ? ' pedido' : ' pedidos'),
                    'destaque' => $d['dia'] === $agora->format('Y-m-d'),
                ], $vg['semana']);
                $graficoTitulo = 'Vendas por dia nos últimos 7 dias. Total: ' . $reais(array_sum(array_column($vg['semana'], 'total'))) . '.';
                $graficoValor = $reaisCurto;
                require __DIR__ . '/_barras.php';
            endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-mais-vendidos">
            <div class="bloco-cabecalho">
                <h2 id="titulo-mais-vendidos"><?= Icon::svg('trophy') ?>Mais vendidos</h2>
                <p>Últimos 7 dias, pela quantidade.</p>
            </div>
            <?php if ($vg['maisVendidos'] === []): ?>
                <div class="lista-vazia"><?= Icon::svg('hamburger') ?><strong>Ainda sem vendas para comparar</strong></div>
            <?php else: $maiorQtd = max(1, ...array_column($vg['maisVendidos'], 'quantidade')); ?>
                <ol class="vg-ranking">
                    <?php foreach ($vg['maisVendidos'] as $pos => $mv): ?>
                        <li>
                            <span class="vg-posicao" aria-hidden="true"><?= $pos + 1 ?></span>
                            <span class="vg-ranking-nome"><?= $e($mv['nome']) ?><small><?= (int) $mv['quantidade'] ?> vendido<?= $mv['quantidade'] === 1 ? '' : 's' ?> · <?= $e($reais($mv['total'])) ?></small></span>
                            <progress max="<?= $maiorQtd ?>" value="<?= (int) $mv['quantidade'] ?>" aria-hidden="true"></progress>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

    <div class="blocos-duplos">
        <section class="bloco" aria-labelledby="titulo-horas">
            <div class="bloco-cabecalho">
                <h2 id="titulo-horas"><?= Icon::svg('clock') ?>Vendas de hoje por hora</h2>
                <p>Mostra os horários de mais movimento.</p>
            </div>
            <?php if ($vg['horas'] === []): ?>
                <div class="lista-vazia"><?= Icon::svg('clock') ?><strong>Nenhuma venda concluída hoje ainda</strong></div>
            <?php else:
                $barras = array_map(static fn (array $hr): array => [
                    'rotulo' => $hr['hora'] . 'h',
                    'valor' => $hr['total'],
                    'dica' => sprintf('%02dh: %s em %d %s', $hr['hora'], $reais($hr['total']), $hr['pedidos'], $hr['pedidos'] === 1 ? 'pedido' : 'pedidos'),
                    'destaque' => $hr['hora'] === $horaAtual,
                ], $vg['horas']);
                $graficoTitulo = 'Vendas de hoje por hora. Total: ' . $reais($h['vendas']) . '.';
                $graficoValor = $reaisCurto;
                require __DIR__ . '/_barras.php';
            endif; ?>
        </section>

        <section class="bloco" aria-labelledby="titulo-estoque">
            <div class="bloco-cabecalho">
                <h2 id="titulo-estoque"><?= Icon::svg('package') ?>Estoque</h2>
                <a class="btn-icone" href="<?= $e($base . '/estoque') ?>"><?= Icon::svg('arrow-up-right', 'icon-sm') ?><span>Ver estoque</span></a>
            </div>
            <?php if ($estoque['totalInsumos'] === 0): ?>
                <div class="lista-vazia">
                    <?= Icon::svg('package') ?><strong>Estoque ainda não cadastrado</strong>
                    <p>Cadastre os insumos para saber quando algo estiver acabando.</p>
                    <p class="lista-vazia-acao"><a class="btn btn-outline btn-sm" href="<?= $e($base . '/estoque/insumos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Cadastrar insumo</a></p>
                </div>
            <?php elseif ($alertasEstoque === [] && $estoque['indisponiveis'] === 0): ?>
                <div class="lista-vazia"><?= Icon::svg('circle-check') ?><strong>Tudo em dia</strong><p>Nenhum insumo acabou ou está abaixo do mínimo.</p></div>
            <?php else: ?>
                <?php if ($estoque['indisponiveis'] > 0): ?>
                    <p class="vg-nota"><?= Icon::svg('ban', 'icon-sm') ?><span><?= $estoque['indisponiveis'] === 1 ? '1 produto está fora de venda' : (int) $estoque['indisponiveis'] . ' produtos estão fora de venda' ?> no cardápio.</span></p>
                <?php endif; ?>
                <ul class="vg-estoque">
                    <?php foreach (array_slice($alertasEstoque, 0, 5) as $ins): $falta = $ins['analise']['situacao'] === \Cardapim\Modules\Inventory\AnaliseEstoque::FALTA; ?>
                        <li>
                            <a href="<?= $e($base . '/estoque/insumos/' . (int) $ins['id']) ?>"><?= $e((string) $ins['nome']) ?></a>
                            <span class="etiqueta <?= $falta ? 'etiqueta-alerta' : 'etiqueta-atencao' ?>"><?= Icon::svg($falta ? 'package-x' : 'triangle-exclamation-point') ?><?= $falta ? 'Acabou' : 'Baixo' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($alertasEstoque) > 5): ?><p class="texto-suave">E mais <?= count($alertasEstoque) - 5 ?>. <a href="<?= $e($base . '/estoque') ?>">Ver todos</a></p><?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <section class="bloco" aria-labelledby="titulo-atalhos">
        <div class="bloco-cabecalho"><h2 id="titulo-atalhos"><?= Icon::svg('zap') ?>Atalhos</h2></div>
        <ul class="atalhos-lista">
            <li><a class="atalho" href="<?= $e($base . '/estoque/produtos/novo') ?>"><?= Icon::svg('hamburger') ?><span><strong>Novo produto</strong><small>Adicionar ao cardápio</small></span><?= Icon::svg('chevron-right', 'icon-sm') ?></a></li>
            <li><a class="atalho" href="<?= $e($base . '/financeiro/contas/nova') ?>"><?= Icon::svg('calendar-clock') ?><span><strong>Nova conta a pagar</strong><small>Aluguel, fornecedor, luz</small></span><?= Icon::svg('chevron-right', 'icon-sm') ?></a></li>
            <li><a class="atalho" href="<?= $e($base . '/equipe/nova') ?>"><?= Icon::svg('users') ?><span><strong>Nova pessoa na equipe</strong><small>Caixa, garçom ou entregador</small></span><?= Icon::svg('chevron-right', 'icon-sm') ?></a></li>
            <li><a class="atalho" href="<?= $e($slugUrl) ?>" target="_blank" rel="noopener"><?= Icon::svg('store') ?><span><strong>Ver meu cardápio</strong><small>Como o cliente vê (abre em outra aba)</small></span><?= Icon::svg('chevron-right', 'icon-sm') ?></a></li>
            <li><a class="atalho" href="<?= $e($base . '/perfil') ?>"><?= Icon::svg('clock') ?><span><strong>Horário de funcionamento</strong><small>Abrir e fechar a loja</small></span><?= Icon::svg('chevron-right', 'icon-sm') ?></a></li>
            <li><a class="atalho" href="<?= $e($base . '/financeiro/relatorio?periodo=mes') ?>" download><?= Icon::svg('file-spreadsheet') ?><span><strong>Planilha do mês</strong><small>Para enviar ao contador</small></span><?= Icon::svg('chevron-right', 'icon-sm') ?></a></li>
        </ul>
    </section>
</div>
