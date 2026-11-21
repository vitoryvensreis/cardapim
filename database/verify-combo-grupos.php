<?php

declare(strict_types=1);

// Conferencia read-only do limite de escolhas por grupo dentro do combo (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-combo-grupos.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['combo_grupos']);
    $atual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['id_combo' => 'bigint unsigned', 'id_grupo' => 'bigint unsigned', 'minimo' => 'tinyint unsigned', 'maximo' => 'tinyint unsigned'] as $nome => $tipo) {
        if (($atual[$nome] ?? null) !== $tipo) {
            throw new RuntimeException("Coluna incorreta: combo_grupos.$nome");
        }
    }
    echo "Combos: limite por grupo confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Combos: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
