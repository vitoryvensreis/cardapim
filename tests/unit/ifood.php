<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/ifood.php (conversao do pedido do iFood e codigos dos avisos)
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Ifood\EventosIfood;
use Cardapim\Services\Ifood\PedidoIfood;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};

// Codigos dos avisos: curto, completo e com prefixo.
$ok(EventosIfood::codigo(['code' => 'PLC']) === 'PLACED', 'PLC = PLACED');
$ok(EventosIfood::codigo(['code' => 'PLC', 'fullCode' => 'PLACED']) === 'PLACED', 'fullCode tem preferencia');
$ok(EventosIfood::codigo(['fullCode' => 'ORDER_CANCELLED']) === 'CANCELLED', 'prefixo ORDER_ removido');
$ok(EventosIfood::codigo(['code' => 'ADR']) === 'ASSIGN_DRIVER', 'ADR = entregador definido');
$ok(EventosIfood::codigo(['code' => 'GOING_TO_ORIGIN']) === 'GOING_TO_ORIGIN', 'codigo longo no campo code');
$ok(EventosIfood::codigo(['code' => 'XYZ']) === 'XYZ', 'codigo desconhecido fica como veio');

// Pedido entregue pelo iFood, pago no app, com cupom, complementos e item por peso.
$base = [
    'id' => 'abc-123', 'displayId' => '7788', 'orderType' => 'DELIVERY', 'orderTiming' => 'IMMEDIATE', 'createdAt' => '2026-11-12T15:00:00Z',
    'customer' => ['name' => 'Maria', 'documentNumber' => '12345678909', 'phone' => ['number' => '0800 700 1234', 'localizer' => '556677']],
    'items' => [
        ['id' => 'i1', 'name' => 'X-Burger', 'externalCode' => 'XB', 'unit' => 'UN', 'quantity' => 2, 'unitPrice' => 20.0, 'optionsPrice' => 3.0, 'totalPrice' => 46.0,
            'observations' => 'sem cebola', 'options' => [['name' => 'Bacon', 'quantity' => 2], ['name' => 'Cheddar', 'quantity' => 1]]],
        ['id' => 'i2', 'name' => 'Picanha', 'unit' => 'KG', 'quantity' => 0.5, 'unitPrice' => 90.0, 'totalPrice' => 45.0],
        ['id' => 'i3', 'name' => 'Agua', 'unit' => 'UN', 'quantity' => 3, 'unitPrice' => 3.33, 'totalPrice' => 10.0],
    ],
    'total' => ['subTotal' => 101.0, 'deliveryFee' => 7.0, 'benefits' => 10.0, 'additionalFees' => 1.5, 'orderAmount' => 99.5],
    'benefits' => [['value' => 10.0, 'sponsorshipValues' => [['name' => 'IFOOD', 'value' => 10.0]], 'campaign' => ['name' => 'Cupom 10']]],
    'payments' => ['prepaid' => 99.5, 'pending' => 0, 'methods' => [['method' => 'CREDIT', 'type' => 'ONLINE', 'prepaid' => true, 'value' => 99.5, 'card' => ['brand' => 'VISA']]]],
    'delivery' => ['deliveredBy' => 'IFOOD', 'pickupCode' => '4321', 'observations' => 'Portao azul',
        'deliveryAddress' => ['streetName' => 'Rua A', 'streetNumber' => '', 'neighborhood' => 'Centro', 'city' => 'Recife', 'state' => 'pe', 'postalCode' => '50.000-000',
            'coordinates' => ['latitude' => -8.05, 'longitude' => -34.9]]],
];
$p = PedidoIfood::converter($base);
$ok($p['tipo'] === 'entrega' && $p['entregue_por'] === 'ifood', 'entrega pelo iFood');
$ok($p['forma_pagamento'] === 'ifood' && $p['pagamento_status'] === 'pago' && $p['troco_para'] === null, 'pago no app');
$ok($p['dados']['pagamento'] === 'Pago pelo app do iFood (Cartão de crédito VISA).', 'texto do pagamento: ' . $p['dados']['pagamento']);
$ok($p['itens'][0]['quantidade'] === 2 && $p['itens'][0]['preco_unitario'] === 2300 && $p['itens'][0]['total'] === 4600, 'preco unitario com complementos');
$ok($p['itens'][0]['observacao'] === '2x Bacon, Cheddar · Obs.: sem cebola', 'complementos e observacao: ' . $p['itens'][0]['observacao']);
$ok($p['itens'][1]['quantidade'] === 1 && $p['itens'][1]['nome'] === 'Picanha (0,5 kg)' && $p['itens'][1]['total'] === 4500 && $p['itens'][1]['baixa'] === 1, 'item por peso vira 1 com a quantidade no nome');
$ok($p['itens'][2]['quantidade'] === 1 && $p['itens'][2]['nome'] === 'Agua (3 un.)' && $p['itens'][2]['baixa'] === 3, 'total que nao divide: 1 linha, baixa 3');
$ok($p['subtotal'] === 10100 && $p['taxa_entrega'] === 850 && $p['desconto'] === 1000 && $p['total'] === 9950, 'valores fecham com o iFood');
$ok($p['subtotal'] + $p['taxa_entrega'] - $p['desconto'] === $p['total'], 'total = subtotal + taxa - desconto (CHECK)');
$ok($p['codigo_coleta'] === '4321' && $p['display_id'] === '7788', 'codigo de coleta e display');
$ok($p['endereco']['numero'] === 'S/N' && $p['endereco']['estado'] === 'PE' && $p['endereco']['cep'] === '50000000', 'endereco normalizado');
$ok($p['endereco']['latitude'] === -8.05, 'coordenadas');
$ok($p['dados']['telefone'] === '0800 700 1234, código 556677' && $p['dados']['documento'] === '12345678909', 'telefone do iFood e documento');
$ok($p['dados']['cupons'][0]['pago_por'] === 'iFood' && $p['dados']['cupons'][0]['valor'] === 1000, 'cupom pago pelo iFood');
$ok($p['observacao'] === 'Portao azul', 'observacao da entrega');
$ok($p['criado_em'] !== null, 'data convertida');

// Diferenca de arredondamento: total do iFood manda.
$dif = $base;
$dif['total']['orderAmount'] = 99.4;
$q = PedidoIfood::converter($dif);
$ok($q['total'] === 9940 && $q['subtotal'] + $q['taxa_entrega'] - $q['desconto'] === 9940, 'diferenca entra como desconto');
$dif['total']['orderAmount'] = 100.0;
$q = PedidoIfood::converter($dif);
$ok($q['total'] === 10000 && $q['taxa_entrega'] === 900, 'diferenca a mais entra como taxa');

// Pagamento na entrega pela loja (dinheiro com troco) e na retirada (cartao).
$loja = $base;
$loja['delivery']['deliveredBy'] = 'MERCHANT';
$loja['payments'] = ['prepaid' => 0, 'pending' => 99.5, 'methods' => [['method' => 'CASH', 'type' => 'OFFLINE', 'value' => 99.5, 'cash' => ['changeFor' => 150]]]];
$q = PedidoIfood::converter($loja);
$ok($q['entregue_por'] === 'loja' && $q['forma_pagamento'] === 'dinheiro' && $q['pagamento_status'] === 'pendente' && $q['troco_para'] === 15000, 'dinheiro com troco na entrega da loja');
$ok($q['dados']['pagamento'] === 'Cobrar na entrega: Dinheiro, troco para R$ 150,00.', 'texto do dinheiro: ' . $q['dados']['pagamento']);
$loja['payments']['methods'][0]['cash']['changeFor'] = 50;
$ok(PedidoIfood::converter($loja)['troco_para'] === null, 'troco menor que o total e ignorado (CHECK)');
$ifoodCobra = $loja;
$ifoodCobra['delivery']['deliveredBy'] = 'IFOOD';
$q = PedidoIfood::converter($ifoodCobra);
$ok($q['forma_pagamento'] === 'ifood' && $q['pagamento_status'] === 'pago' && str_starts_with($q['dados']['pagamento'], 'O cliente paga ao entregador do iFood'), 'entregador do iFood cobra: repasse do iFood');
$retirada = $base;
$retirada['orderType'] = 'TAKEOUT';
unset($retirada['delivery']);
$retirada['payments'] = ['methods' => [['method' => 'DEBIT', 'type' => 'OFFLINE', 'value' => 99.5, 'card' => ['brand' => 'ELO']]]];
$q = PedidoIfood::converter($retirada);
$ok($q['tipo'] === 'retirada' && $q['entregue_por'] === null && $q['endereco'] === null && $q['forma_pagamento'] === 'debito', 'retirada com debito');
$ok($q['dados']['pagamento'] === 'Cobrar na retirada: Cartão de débito ELO.', 'texto da retirada: ' . $q['dados']['pagamento']);
$vale = $retirada;
$vale['payments']['methods'][0]['method'] = 'MEAL_VOUCHER';
$ok(PedidoIfood::converter($vale)['forma_pagamento'] === 'credito', 'vale-refeicao na maquininha');

// Agendado e comer no local.
$ag = $base;
$ag['orderTiming'] = 'SCHEDULED';
$ag['schedule'] = ['deliveryDateTimeStart' => '2026-11-12T22:30:00Z'];
$q = PedidoIfood::converter($ag);
$ok($q['dados']['agendado_para'] !== null && str_contains((string) $q['observacao'], 'Pedido agendado para'), 'pedido agendado');
$mesa = $retirada;
$mesa['orderType'] = 'DINE_IN';
$mesa['indoor'] = ['table' => '7'];
$ok(str_contains((string) PedidoIfood::converter($mesa)['observacao'], 'Comer no local, mesa 7.'), 'comer no local com mesa');

// Entrada invalida.
try {
    PedidoIfood::converter(['id' => '../x']);
    $ok(false, 'id invalido recusado');
} catch (InvalidArgumentException) {
    $ok(true, 'id invalido recusado');
}

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) no iFood.\n");
    exit(1);
}
echo "OK: iFood (avisos e conversao do pedido).\n";
