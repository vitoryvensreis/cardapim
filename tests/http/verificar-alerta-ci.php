<?php

declare(strict_types=1);

// Apos tests/http/pedidos.sh e caixa.sh: confere que pedido feito pelo cardapio gravou o evento "pedido.novo"
// (canal pedidos, com id e numero), que e o que acende o aviso de pedido novo na gerencia e no caixa.
// Somente leitura e somente no banco cardapim_ci.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: somente no banco cardapim_ci.\n");
    exit(1);
}
$q = $db->query("SELECT o.id_restaurante, o.dados FROM outbox_eventos o WHERE o.canal = 'pedidos' AND o.tipo = 'pedido.novo' ORDER BY o.id DESC LIMIT 50");
$eventos = $q->fetchAll(PDO::FETCH_ASSOC);
if ($eventos === []) {
    fwrite(STDERR, "Nenhum evento pedido.novo na Outbox: o aviso de pedido novo nunca acenderia.\n");
    exit(1);
}
$confere = $db->prepare('SELECT numero FROM pedidos WHERE id = ? AND id_restaurante = ?');
foreach ($eventos as $e) {
    $d = json_decode((string) $e['dados'], true);
    if (!is_array($d) || !is_int($d['id'] ?? null) || !is_int($d['numero'] ?? null)) {
        fwrite(STDERR, "Evento pedido.novo sem id/numero: {$e['dados']}\n");
        exit(1);
    }
    $confere->execute([$d['id'], $e['id_restaurante']]);
    if ((int) $confere->fetchColumn() !== $d['numero']) {
        fwrite(STDERR, "Evento pedido.novo aponta para pedido de outro restaurante ou numero errado.\n");
        exit(1);
    }
}
echo 'OK: ' . count($eventos) . " evento(s) pedido.novo com id e numero do proprio restaurante.\n";
