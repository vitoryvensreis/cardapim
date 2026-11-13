<?php

declare(strict_types=1);

// Somente leitura: confirma as varias comandas por mesa (pedidos.comanda_nome e mesa_aberta sem indice unico).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-comandas.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $tipo = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'comanda_nome'")->fetch(PDO::FETCH_NUM);
    if ($tipo === false || $tipo[0] !== 'varchar(40)' || $tipo[1] !== 'YES') {
        throw new RuntimeException('Coluna pedidos.comanda_nome incorreta ou ausente.');
    }
    $indices = $db->query(<<<'SQL'
SELECT INDEX_NAME, MIN(NON_UNIQUE) FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME IN ('uq_pedidos_mesa_aberta', 'idx_pedidos_mesa_aberta')
GROUP BY INDEX_NAME
SQL)->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($indices['uq_pedidos_mesa_aberta']) || (int) ($indices['idx_pedidos_mesa_aberta'] ?? 0) !== 1) {
        throw new RuntimeException('Indice de mesa_aberta ainda unico ou ausente.');
    }
    echo "Comandas confirmadas: varias por mesa, com nome (pedidos.comanda_nome).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao das comandas: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
