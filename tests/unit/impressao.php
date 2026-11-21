<?php

declare(strict_types=1);

// Teste sem banco do cupom enviado ao Deliveryzim Agent: php tests/unit/impressao.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Impressao\CupomImpressao;
use Cardapim\Services\Impressao\FilaImpressao;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};
$textos = static function (array $blocos): string {
    return implode("\n", array_map(static fn (array $b): string => implode(' | ', array_map(
        static fn ($v): string => is_array($v) ? implode(' / ', $v) : (string) $v, $b
    )), $blocos));
};
$item = static fn (int $id, string $nome, string $total, ?string $cancelado = null, string $obs = ''): array => [
    'id' => $id, 'id_produto' => $id, 'nome' => $nome, 'preco_unitario' => $total, 'quantidade' => '1.000', 'unidade' => 'un',
    'observacao' => $obs, 'complementos_texto' => '', 'total' => $total, 'criado_em' => '2026-11-20 12:00:00',
    'cancelado_em' => $cancelado, 'motivo_cancelamento' => null, 'autor' => 'Ana',
];
$comanda = static fn (string $tipo, array $itens, string $obs = ''): array => [
    'pedido' => ['id' => 9, 'numero' => 42, 'tipo' => $tipo, 'servico_percentual' => '10.00', 'pessoas' => null, 'observacao' => $obs],
    'rotulo' => $tipo === 'mesa' ? 'Mesa 5 · João' : 'Balcão · Maria',
    'rotulo_mesa' => $tipo === 'mesa' ? 'Mesa 5' : 'Balcão',
    'nome' => $tipo === 'mesa' ? 'João' : 'Maria',
    'itens' => $itens,
    'subtotal' => 3000, 'servico' => 300, 'desconto' => 0, 'total' => 3300, 'pago' => 0, 'restante' => 3300,
];

$itens = [$item(10, 'X-Bacon', '20.00', null, 'sem cebola'), $item(11, 'Suco', '5.00', '2026-11-20 12:01:00'), $item(12, 'Refri', '10.00')];

// Pre-conta do garcom: mesa no topo (titulo grande), todos os itens ativos e o total.
$c = CupomImpressao::comanda($comanda('mesa', $itens), 'Bar do Zé', 'pre_conta', 0, 'Ana');
$t = $textos($c['blocos']);
$ok($c['blocos'][2] === ['t' => 'titulo', 'texto' => 'MESA 5'], 'mesa identificada no titulo');
$ok(str_contains($t, 'Comanda: João'), 'nome da comanda');
$ok(str_contains($t, 'PRÉ-CONTA · Pedido #42'), 'tipo do cupom e numero');
$ok(str_contains($t, 'X-Bacon') && str_contains($t, 'Refri') && !str_contains($t, 'Suco'), 'itens ativos, sem o cancelado');
$ok(str_contains($t, 'Obs.: sem cebola'), 'observacao do item');
$ok(str_contains($t, 'TOTAL | R$ 33,00'), 'total com servico');
$ok($c['ate_item'] === 12 && $c['itens'] === 2, 'maior item impresso e quantidade');
$ok(str_starts_with($c['titulo'], 'PRÉ-CONTA · Mesa 5'), 'titulo da fila');

// So os itens novos: o que veio depois do item 10, sem os totais completos.
$n = CupomImpressao::comanda($comanda('mesa', $itens), 'Bar do Zé', 'itens_novos', 10);
$tn = $textos($n['blocos']);
$ok($n['itens'] === 1 && str_contains($tn, 'Refri') && !str_contains($tn, 'X-Bacon'), 'so o item novo');
$ok(str_contains($tn, 'ITENS NOVOS') && str_contains($tn, 'MESA 5'), 'itens novos com a mesa');
$ok(!str_contains($tn, 'Não é documento fiscal') && str_contains($tn, 'Total da comanda até agora'), 'sem fechamento de conta');
$ok(CupomImpressao::comanda($comanda('mesa', $itens), 'Bar', 'itens_novos', 12)['itens'] === 0, 'nada novo depois do ultimo');

// Balcao: observacao do pedido sai na impressao.
$b = CupomImpressao::comanda($comanda('balcao', $itens, 'Para viagem'), 'Bar do Zé', 'conta');
$tb = $textos($b['blocos']);
$ok(str_contains($tb, 'BALCÃO') && str_contains($tb, 'Cliente: Maria'), 'balcao identificado');
$ok(str_contains($tb, 'OBSERVAÇÃO') && str_contains($tb, 'Para viagem'), 'observacao do balcao impressa');
$ok(str_contains($tb, 'CONTA · Pedido #42') && str_contains($tb, 'Não é documento fiscal.'), 'conta do caixa');

$erro = false;
try {
    CupomImpressao::comanda($comanda('mesa', $itens), 'Bar', 'qualquer');
} catch (InvalidArgumentException) {
    $erro = true;
}
$ok($erro, 'modo desconhecido recusado');

// Pedido de entrega.
$p = CupomImpressao::pedido([
    'numero' => 77, 'tipo' => 'entrega', 'origem' => 'cardapio', 'criado_em' => '2026-11-20 19:00:00', 'cliente_nome' => 'Carla',
    'cliente_whatsapp' => '85999998888', 'entrega_logradouro' => 'Rua A', 'entrega_numero' => '10', 'entrega_complemento' => '',
    'entrega_bairro' => 'Centro', 'entrega_cidade' => 'Fortaleza', 'entrega_referencia' => '', 'observacao' => 'Tocar a campainha',
    'subtotal' => '30.00', 'taxa_entrega' => '5.00', 'desconto' => '0.00', 'total' => '35.00', 'forma_pagamento' => 'dinheiro', 'troco_para' => '50.00',
], [$item(1, 'Pizza', '30.00')], 'Bar do Zé');
$tp = $textos($p['blocos']);
$ok(str_contains($tp, 'PEDIDO #77') && str_contains($tp, 'ENTREGA'), 'pedido de entrega');
$ok(str_contains($tp, '(85) 99999-8888') && str_contains($tp, 'Rua A, 10'), 'telefone e endereco');
$ok(str_contains($tp, 'Tocar a campainha') && str_contains($tp, 'Troco para R$ 50,00 | R$ 15,00'), 'observacao e troco');

// Blocos conhecidos pelo Agent.
$tipos = [];
foreach ([$c, $n, $b, $p] as $cupom) {
    foreach ($cupom['blocos'] as $bloco) {
        $tipos[$bloco['t']] = true;
    }
}
$ok(array_diff(array_keys($tipos), ['titulo', 'centro', 'destaque', 'texto', 'linha', 'valor', 'item']) === [], 'so blocos que o Agent conhece');
$ok(json_encode($c['blocos'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) !== '', 'cupom vira JSON');

// Vias de producao: cada item na impressora do produto; sem marcacao vai para a padrao (7 = Cozinha).
$espeto = $item(20, 'Espeto de picanha', '15.00') + ['id_produto' => 101];
$espeto['id_produto'] = 101;
$cerveja = $item(21, 'Cerveja', '9.00');
$cerveja['id_produto'] = 102;
$prato = $item(22, 'Baião de dois', '30.00', null, 'sem coentro');
$prato['id_produto'] = 103;
$combo = $item(23, 'Combo espeto + farofa', '25.00');
$combo['id_produto'] = 104;
$cancelado = $item(24, 'Espeto de frango', '12.00', '2026-11-20 12:05:00');
$cancelado['id_produto'] = 101;
$grupos = CupomImpressao::dividir([$espeto, $cerveja, $prato, $combo, $cancelado], [101 => [5], 102 => [6], 104 => [5, 7]], 7);
$nomes = static fn (array $lista): array => array_map(static fn (array $i): string => $i['nome'], $lista);
$ok($nomes($grupos[5] ?? []) === ['Espeto de picanha', 'Combo espeto + farofa'], 'churrasqueira so com os espetos');
$ok($nomes($grupos[6] ?? []) === ['Cerveja'], 'bar so com a bebida');
$ok($nomes($grupos[7] ?? []) === ['Baião de dois', 'Combo espeto + farofa'], 'cozinha: sem marcacao + produto em duas estacoes');
$ok(!in_array('Espeto de frango', array_merge(...array_map($nomes, array_values($grupos))), true), 'item cancelado nao sai');

$via = CupomImpressao::producao(CupomImpressao::cabecaComanda($comanda('mesa', [])), 'Churrasqueira', $grupos[5], 'Ana');
$tv = $textos($via);
$ok($via[0] === ['t' => 'centro', 'texto' => 'PRODUÇÃO · CHURRASQUEIRA'] && $via[2] === ['t' => 'titulo', 'texto' => 'MESA 5'], 'via com a estacao e a mesa');
$ok(str_contains($tv, 'Espeto de picanha') && !str_contains($tv, 'Cerveja') && !str_contains($tv, 'TOTAL'), 'so os itens da estacao, sem total');
$vb = $textos(CupomImpressao::producao(CupomImpressao::cabecaComanda($comanda('balcao', [], 'Para viagem')), 'Cozinha', $grupos[7]));
$ok(str_contains($vb, 'BALCÃO') && str_contains($vb, 'Para viagem') && str_contains($vb, 'Obs.: sem coentro'), 'balcao com observacao na via');
$cp = CupomImpressao::cabecaPedido(['numero' => 88, 'tipo' => 'entrega', 'origem' => 'ifood', 'ifood_display_id' => '1234', 'cliente_nome' => 'Rita', 'observacao' => '']);
$ok($cp['titulo'] === 'PEDIDO #88' && $cp['linha'] === 'ENTREGA · iFood #1234' && $cp['detalhe'] === 'Cliente: Rita', 'cabecalho da via do delivery');

// Aviso de cancelamento (na impressora do item).
$canc = CupomImpressao::cancelamento(['titulo' => 'MESA 5', 'linha' => 'Comanda: Ana', 'detalhe' => 'Pedido #9', 'observacao' => ''], 'Cozinha',
    [['id' => 1, 'nome' => 'Picanha', 'quantidade' => '2.000', 'unidade' => 'un', 'total' => '80.00', 'preco_unitario' => '40.00', 'observacao' => 'mal passada', 'complementos_texto' => '']],
    'Cliente desistiu', false, 'Joao');
$txtC = json_encode($canc, JSON_UNESCAPED_UNICODE);
$ok(str_contains($txtC, 'CANCELAMENTO · COZINHA') && str_contains($txtC, 'MESA 5') && str_contains($txtC, 'ITEM CANCELADO') && str_contains($txtC, 'Picanha')
    && str_contains($txtC, 'Cliente desistiu') && !str_contains($txtC, '80,00'), 'cancelamento: estacao, mesa, item, motivo e sem valor');
$ok(str_contains(json_encode(CupomImpressao::cancelamento(['titulo' => 'PEDIDO #3', 'linha' => '', 'detalhe' => 'Delivery', 'observacao' => ''], 'Bar', [], '', true), JSON_UNESCAPED_UNICODE), 'PEDIDO CANCELADO'), 'cancelamento do pedido inteiro');

// Situacao unica do lote (impressao dividida).
$c1 = ['apelido' => 'Churrasqueira', 'entregue_em' => '2026-11-20 12:00:00', 'resultado' => 'ok', 'erro' => null, 'vencido' => 0];
$c2 = ['apelido' => 'Bar', 'entregue_em' => '2026-11-20 12:00:00', 'resultado' => null, 'erro' => null, 'vencido' => 0];
$ok(FilaImpressao::situacaoLote([$c1, $c2])['status'] === 'imprimindo', 'lote imprimindo enquanto falta uma');
$ok(FilaImpressao::situacaoLote([$c1, ['resultado' => 'ok'] + $c2]) === ['status' => 'impresso', 'mensagem' => 'Impresso em Churrasqueira e Bar.'], 'lote impresso em todas');
$ok(str_contains(FilaImpressao::situacaoLote([$c1, ['resultado' => 'erro', 'erro' => 'Sem papel'] + $c2])['mensagem'], 'Bar não imprimiu: Sem papel'), 'lote com falha diz a impressora');
$ok(FilaImpressao::situacaoLote([$c1, ['entregue_em' => null, 'vencido' => 1] + $c2])['status'] === 'expirada', 'lote com estacao sem Agent');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: cupom do Agent (pre-conta, itens novos, balcao com observacao, entrega).\n";
