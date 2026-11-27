<?php

declare(strict_types=1);

// Conferencia read-only do numero do dia do pedido (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-numero-do-dia.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME IN ('dia_operacional', 'numero_dia')");
    $cols = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[$c['COLUMN_NAME']] = $c;
    }
    if (($cols['dia_operacional']['COLUMN_TYPE'] ?? '') !== 'date' || ($cols['dia_operacional']['IS_NULLABLE'] ?? '') !== 'YES') {
        throw new RuntimeException('Falta pedidos.dia_operacional (DATE NULL).');
    }
    if (!str_starts_with((string) ($cols['numero_dia']['COLUMN_TYPE'] ?? ''), 'int') || ($cols['numero_dia']['IS_NULLABLE'] ?? '') !== 'YES') {
        throw new RuntimeException('Falta pedidos.numero_dia (INT NULL).');
    }
    $q = $db->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'uq_pedidos_numero_dia' AND NON_UNIQUE = 0");
    if ((string) $q->fetchColumn() !== 'id_restaurante,dia_operacional,numero_dia') {
        throw new RuntimeException('Falta o indice unico uq_pedidos_numero_dia (id_restaurante, dia_operacional, numero_dia).');
    }
    echo "Numero do dia confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Numero do dia: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
