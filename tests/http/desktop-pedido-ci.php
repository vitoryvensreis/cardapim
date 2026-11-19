<?php

declare(strict_types=1);

// Cria um pedido de retirada "Novo" (status recebido) + evento pedido.novo, como o cardapio online faria,
// para tests/http/desktop-pedidos.sh testar o "Receber" do Deliveryzim Agent.
// Uso: php tests/http/desktop-pedido-ci.php <slug>   -> imprime o id do pedido.
// SOMENTE no banco de CI (cardapim_ci).
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: pedidos de teste so podem ser criados no banco cardapim_ci.\n");
    exit(1);
}

$q = $db->prepare('SELECT id FROM restaurantes WHERE slug = ?');
$q->execute([(string) ($argv[1] ?? '')]);
$idRestaurante = (int) $q->fetchColumn();
if ($idRestaurante < 1) {
    fwrite(STDERR, "Restaurante nao encontrado.\n");
    exit(1);
}

$db->beginTransaction();
$q = $db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ? FOR UPDATE');
$q->execute([$idRestaurante]);
$numero = (int) $q->fetchColumn();
$db->prepare("INSERT INTO pedidos (id_restaurante, numero, origem, tipo, status, forma_pagamento, pagamento_status,
        subtotal, taxa_entrega, desconto, total, cliente_nome, chave_idempotencia)
    VALUES (?, ?, 'cardapio', 'retirada', 'recebido', 'dinheiro', 'pendente', 10.00, 0.00, 0.00, 10.00, 'Cliente Agent CI', ?)")
    ->execute([$idRestaurante, $numero, bin2hex(random_bytes(16))]);
$id = (int) $db->lastInsertId();
$db->prepare("INSERT INTO pedido_itens (id_pedido, id_restaurante, nome, preco_unitario, quantidade, total)
    VALUES (?, ?, 'Item do Agent CI', 10.00, 1, 10.00)")->execute([$id, $idRestaurante]);
\Cardapim\Realtime\TempoReal::registrar($db, $idRestaurante, 'pedidos', 'pedido.novo', ['id' => $id, 'numero' => $numero]);
$db->commit();
echo $id;
