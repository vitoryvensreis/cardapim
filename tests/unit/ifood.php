<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/ifood.php (conversao do pedido do iFood e codigos dos avisos)
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Ifood\FusoLojaIfood;
use Cardapim\Services\Ifood\EventosIfood;
use Cardapim\Services\Ifood\PedidoIfood;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};

// Fuso de interrupcoes sempre Brasilia, mesmo para loja no Acre.
$ok(FusoLojaIfood::sugerir(['address' => ['state' => 'AC', 'city' => 'Rio Branco']]) === 'America/Sao_Paulo', 'fuso fixo mesmo no Acre');
$ok(FusoLojaIfood::timezone('America/Manaus')->getName() === 'America/Sao_Paulo', 'timezone nao aceita mudanca');
$ok(!FusoLojaIfood::valido('America/Manaus'), 'outros fusos nao configuraveis');

// Início automático: respeita Brasilia e tem precisão de segundos (+10 s).
$agoraPausa = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$padraoPausa = IfoodLojaService::novaPausa();
$inicioPausa = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $padraoPausa['inicio'], new DateTimeZone('America/Sao_Paulo'));
$fimPausa = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $padraoPausa['fim'], new DateTimeZone('America/Sao_Paulo'));
$segundosAdiante = $inicioPausa !== false ? $inicioPausa->getTimestamp() - $agoraPausa->getTimestamp() : 0;
$ok($segundosAdiante >= 8 && $segundosAdiante <= 12, 'inicio padrao dez segundos no futuro');
$ok($inicioPausa !== false && $fimPausa !== false
    && $fimPausa->getTimestamp() - $inicioPausa->getTimestamp() === 3600, 'fim automatico uma hora depois com segundos');

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

// Horarios da loja no iFood (formulario -> turnos com duracao em minutos).
$h = IfoodLojaService::lerHorarios([
    'SATURDAY' => [['abre' => '10:00', 'fecha' => '19:00']],
    'SUNDAY' => [['abre' => '17:00', 'fecha' => '23:00'], ['abre' => '09:00', 'fecha' => '12:00'], ['abre' => '13:00', 'fecha' => '16:00'], ['abre' => '', 'fecha' => '']],
]);
$ok($h['erros'] === [], 'horarios do checklist validos');
$ok($h['turnos'] === [
    ['dia' => 'SATURDAY', 'inicio' => '10:00', 'duracao' => 540],
    ['dia' => 'SUNDAY', 'inicio' => '09:00', 'duracao' => 180],
    ['dia' => 'SUNDAY', 'inicio' => '13:00', 'duracao' => 180],
    ['dia' => 'SUNDAY', 'inicio' => '17:00', 'duracao' => 360],
], 'turnos em ordem e com duracao');
$ok(IfoodLojaService::lerHorarios(['MONDAY' => [['abre' => '18:00', 'fecha' => '00:00']]])['turnos'][0]['duracao'] === 360, 'fecha a meia-noite');
$ok(isset(IfoodLojaService::lerHorarios(['MONDAY' => [['abre' => '09:00', 'fecha' => '12:00'], ['abre' => '11:00', 'fecha' => '13:00']]])['erros']['MONDAY.1']), 'sobreposicao recusada');
$ok(isset(IfoodLojaService::lerHorarios(['MONDAY' => [['abre' => '19:00', 'fecha' => '10:00']]])['erros']['MONDAY.0']), 'fecha antes de abrir recusado');
$ok(isset(IfoodLojaService::lerHorarios(['MONDAY' => [['abre' => '25:00', 'fecha' => '10:00']]])['erros']['MONDAY.0']), 'hora invalida recusada');
$ok(IfoodLojaService::paraFormulario([['dia' => 'SUNDAY', 'inicio' => '17:00', 'duracao' => 420]]) === ['SUNDAY' => [['abre' => '17:00', 'fecha' => '00:00']]], 'volta para o formulario');
// Horario do Perfil para o iFood: dias na ordem do iFood, turno que passa da meia-noite dividido, sempre aberto = 24 h.
$ok(IfoodLojaService::turnosDoPerfil(false, [
    ['dia_semana' => 0, 'abre' => '09:00:00', 'fecha' => '12:00:00'],
    ['dia_semana' => 1, 'abre' => '11:00:00', 'fecha' => '15:00:00'],
    ['dia_semana' => 1, 'abre' => '18:00:00', 'fecha' => '02:00:00'],
]) === [
    ['dia' => 'MONDAY', 'inicio' => '11:00', 'duracao' => 240],
    ['dia' => 'MONDAY', 'inicio' => '18:00', 'duracao' => 360],
    ['dia' => 'TUESDAY', 'inicio' => '00:00', 'duracao' => 120],
    ['dia' => 'SUNDAY', 'inicio' => '09:00', 'duracao' => 180],
], 'perfil convertido (madrugada vira o dia seguinte)');
$ok(IfoodLojaService::turnosDoPerfil(false, [['dia_semana' => 6, 'abre' => '18:00', 'fecha' => '00:00']]) === [['dia' => 'SATURDAY', 'inicio' => '18:00', 'duracao' => 360]], 'fecha a meia-noite nao cria turno no dia seguinte');
$ok(IfoodLojaService::turnosDoPerfil(false, [['dia_semana' => 6, 'abre' => '20:00', 'fecha' => '03:00'], ['dia_semana' => 0, 'abre' => '03:00', 'fecha' => '05:00']])
    === [['dia' => 'SATURDAY', 'inicio' => '20:00', 'duracao' => 240], ['dia' => 'SUNDAY', 'inicio' => '00:00', 'duracao' => 300]], 'turnos encostados se juntam');
$sempre = IfoodLojaService::turnosDoPerfil(true, []);
$ok(count($sempre) === 7 && $sempre[0] === ['dia' => 'MONDAY', 'inicio' => '00:00', 'duracao' => 1440], 'sempre aberto = 24 h todos os dias');
$ok(IfoodLojaService::turnosDoPerfil(false, []) === [], 'sem horario no perfil');

// Pausa ativa: so a que ja comecou e ainda nao terminou.
$agora = new DateTimeImmutable('2026-11-17 12:00:00');
$ativas = IfoodLojaService::pausasAtivas([
    ['id' => 'a', 'descricao' => 'agora', 'inicio' => $agora->modify('-10 minutes')->format(DATE_ATOM), 'fim' => $agora->modify('+20 minutes')->format(DATE_ATOM)],
    ['id' => 'b', 'descricao' => 'futura', 'inicio' => $agora->modify('+1 hour')->format(DATE_ATOM), 'fim' => $agora->modify('+2 hours')->format(DATE_ATOM)],
    ['id' => 'c', 'descricao' => 'acabou', 'inicio' => $agora->modify('-2 hours')->format(DATE_ATOM), 'fim' => $agora->modify('-1 hour')->format(DATE_ATOM)],
], $agora);
$ok(array_column($ativas, 'id') === ['a'], 'so a pausa valendo agora');
// Horario do iFood: sem fuso = UTC (12:49 UTC = 09:49 em Fortaleza), nunca horario local.
$ok(IfoodLojaService::dataIfood('2026-11-17T12:49:00')?->format('H:i') === '09:49', 'hora do iFood sem fuso e UTC');
$ok(IfoodLojaService::dataIfood('2026-11-17T12:49:00Z')?->format('H:i') === '09:49', 'hora do iFood com Z');
$ok(IfoodLojaService::dataIfood('2026-11-17T09:49:00-03:00')?->format('H:i') === '09:49', 'hora com fuso');
$ok(IfoodLojaService::utc(new DateTimeImmutable('2026-11-17 09:49:00')) === '2026-11-17T12:49:00Z', 'envio em UTC');
$ok(IfoodLojaService::pausasAtivas([['id' => 'u', 'descricao' => '', 'inicio' => gmdate('Y-m-d\TH:i:s', time() - 60), 'fim' => gmdate('Y-m-d\TH:i:s', time() + 600)]]) !== [], 'pausa sem fuso valendo agora');

$ok(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', IfoodApi::uuid()) === 1, 'uuid v4');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) no iFood.\n");
    exit(1);
}
echo "OK: iFood (avisos e conversao do pedido).\n";
