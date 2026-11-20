<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-entregador-valores.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='restaurante_entrega_pagamento' AND COLUMN_NAME='entregador_ve_valores'");
    $coluna = $q->fetch(PDO::FETCH_ASSOC);
    if (!$coluna || !str_starts_with((string) $coluna['COLUMN_TYPE'], 'tinyint') || $coluna['IS_NULLABLE'] !== 'NO' || (string) $coluna['COLUMN_DEFAULT'] !== '1') {
        throw new RuntimeException('Falta restaurante_entrega_pagamento.entregador_ve_valores (TINYINT NOT NULL DEFAULT 1).');
    }
    echo "Valores do entregador: coluna confirmada.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Valores do entregador: falha de verificacao: " . $e->getMessage() . "\n");
    exit(1);
}
