<?php

declare(strict_types=1);

// Trocar forma de pagamento no delivery (sem banco): quem pode trocar e a base da taxa.
// php tests/unit/trocar-forma.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Pedidos\TaxaPagamento;
use Cardapim\Services\Pedidos\TrocarFormaPagamento as T;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

$entrega = ['tipo' => 'entrega', 'origem' => 'cardapio', 'status' => 'saiu_entrega', 'pagamento_status' => 'pendente', 'forma_pagamento' => 'dinheiro',
    'id_entregador' => 7, 'total' => '55.00', 'taxa_pagamento' => '0.00'];
$ok(T::motivoBloqueio($entrega) === null, 'caixa troca a forma de entrega pendente');
$ok(T::motivoBloqueio($entrega, 7) === null, 'motoboy troca a entrega que esta com ele');
$ok(T::motivoBloqueio($entrega, 8) === 'Esta entrega não está com você.', 'motoboy nao troca entrega de outro motoboy');
$ok(T::motivoBloqueio(['status' => 'em_preparo'] + $entrega, 7) === 'Esta entrega não está com você.', 'motoboy so troca em rota');
$ok(T::motivoBloqueio(['status' => 'em_preparo'] + $entrega) === null, 'caixa troca antes do despacho');
$ok(T::motivoBloqueio(['pagamento_status' => 'pago'] + $entrega) !== null, 'pedido pago nao troca');
$ok(T::motivoBloqueio(['origem' => 'ifood'] + $entrega) !== null, 'pedido do iFood nao troca');
$ok(T::motivoBloqueio(['forma_pagamento' => 'ifood'] + $entrega) !== null, 'forma iFood nao troca');
$ok(T::motivoBloqueio(['status' => 'concluido'] + $entrega) !== null, 'pedido concluido nao troca');
$ok(T::motivoBloqueio(['status' => 'cancelado'] + $entrega) !== null, 'pedido cancelado nao troca');
$ok(T::motivoBloqueio(['status' => 'aguardando_pagamento'] + $entrega) !== null, 'pedido esperando o Pix do app nao troca');
$ok(T::motivoBloqueio(['tipo' => 'mesa', 'status' => 'aberto'] + $entrega) !== null, 'comanda de mesa nao usa a troca');
$ok(T::motivoBloqueio(['tipo' => 'retirada', 'status' => 'pronto', 'id_entregador' => null] + $entrega) === null, 'retirada pendente troca no caixa');

// Base da taxa = total sem a taxa atual; a taxa nova sai sobre essa base (mesma conta do CriarPedido).
$ok(T::base(['total' => '51.75', 'taxa_pagamento' => '1.75']) === 5000, 'base tira a taxa da forma anterior');
$credito = ['tipo' => 'percentual', 'valor' => '3.50'];
$ok(TaxaPagamento::calcular(T::base($entrega), $credito) === 193, 'dinheiro -> credito 3,5% sobre R$ 55,00 = R$ 1,93');
$ok(T::base(['total' => '56.93', 'taxa_pagamento' => '1.93']) === 5500, 'credito -> dinheiro: a taxa sai e volta a R$ 55,00');
$ok(T::base(['total' => '30.00']) === 3000, 'pedido sem coluna de taxa');

if ($falhas > 0) {
    echo "$falhas falha(s) na troca da forma.\n";
    exit(1);
}
echo "Trocar forma de pagamento: tudo certo.\n";
