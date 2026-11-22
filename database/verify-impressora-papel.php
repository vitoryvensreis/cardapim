<?php

declare(strict_types=1);

// Conferencia read-only das colunas papel, logo e margem_corte das impressoras (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-impressora-papel.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $esperado = ['papel' => ['tinyint unsigned', '80'], 'logo' => ['tinyint(1)', '1'], 'margem_corte' => ['tinyint unsigned', '4']];
    foreach ($esperado as $coluna => [$tipo, $padrao]) {
        $q = $db->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'impressoras' AND COLUMN_NAME = ?");
        $q->execute([$coluna]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if ($c === false || $c['COLUMN_TYPE'] !== $tipo || $c['IS_NULLABLE'] !== 'NO' || (string) $c['COLUMN_DEFAULT'] !== $padrao) {
            throw new RuntimeException("Falta impressoras.$coluna ($tipo NOT NULL DEFAULT $padrao).");
        }
    }
    echo "Impressoras: papel, logo e espaco antes do corte confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Impressoras: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
