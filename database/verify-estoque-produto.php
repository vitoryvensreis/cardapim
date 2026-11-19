<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-estoque-produto.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='insumos' AND COLUMN_NAME='id_produto_proprio'");
    $coluna = $q->fetch(PDO::FETCH_ASSOC);
    if (!$coluna || $coluna['COLUMN_TYPE'] !== 'bigint unsigned' || $coluna['IS_NULLABLE'] !== 'YES') {
        throw new RuntimeException('Falta insumos.id_produto_proprio (BIGINT UNSIGNED NULL).');
    }
    $q = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='insumos' AND INDEX_NAME='uq_insumos_produto_proprio' AND NON_UNIQUE=0");
    if ((int) $q->fetchColumn() !== 2) {
        throw new RuntimeException('Falta UNIQUE restaurante + produto no insumo proprio.');
    }
    echo "Estoque proprio do produto: coluna e unicidade confirmadas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Estoque proprio: falha de verificacao: " . $e->getMessage() . "\n");
    exit(1);
}
