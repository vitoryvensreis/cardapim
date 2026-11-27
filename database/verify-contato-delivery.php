<?php

declare(strict_types=1);

// Conferencia read-only do endereco e do telefone na via do delivery aceito (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-contato-delivery.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    foreach (['delivery_com_endereco', 'delivery_com_telefone'] as $c) {
        $q = $db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $q->execute(['restaurante_impressao_config', $c]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false || $r['COLUMN_TYPE'] !== 'tinyint(1)' || $r['IS_NULLABLE'] !== 'NO' || (string) $r['COLUMN_DEFAULT'] !== '0') {
            throw new RuntimeException("Falta restaurante_impressao_config.$c (TINYINT(1) NOT NULL DEFAULT 0).");
        }
    }
    echo "Endereco e telefone do delivery confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Contato do delivery: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
