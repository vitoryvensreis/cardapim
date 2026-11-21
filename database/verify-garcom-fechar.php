<?php

declare(strict_types=1);

// Conferencia read-only da permissao "garcom fecha a conta" (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-garcom-fechar.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurante_garcom_permissoes' AND COLUMN_NAME = 'fechar_conta'");
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if ($c === false || $c['COLUMN_TYPE'] !== 'tinyint(1)' || $c['IS_NULLABLE'] !== 'NO' || (string) $c['COLUMN_DEFAULT'] !== '1') {
        throw new RuntimeException('Falta fechar_conta (TINYINT(1) NOT NULL DEFAULT 1).');
    }
    echo "Permissoes do garcom: fechar conta confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Permissoes do garcom: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
