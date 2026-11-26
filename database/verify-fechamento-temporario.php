<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes' AND COLUMN_NAME = 'fechamentos_temporarios'");
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['DATA_TYPE'] !== 'json' || $r['IS_NULLABLE'] !== 'YES') {
        throw new RuntimeException('Coluna fechamentos_temporarios ausente ou incorreta.');
    }
    echo "Fechamento temporario confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
