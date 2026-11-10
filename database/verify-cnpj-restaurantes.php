<?php

declare(strict_types=1);

// Somente leitura: confirma tipo, unicidade e CHECK de restaurantes.cnpj.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-cnpj-restaurantes.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $column = $db->query(<<<'SQL'
SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes' AND COLUMN_NAME = 'cnpj'
SQL)->fetch();
    if (!$column || $column['DATA_TYPE'] !== 'char' || (int) $column['CHARACTER_MAXIMUM_LENGTH'] !== 14) {
        throw new RuntimeException('restaurantes.cnpj deve ser CHAR(14).');
    }
    $unique = $db->query(<<<'SQL'
SELECT NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes' AND COLUMN_NAME = 'cnpj'
  AND INDEX_NAME = 'uq_restaurantes_cnpj'
SQL)->fetchColumn();
    if ($unique === false || (int) $unique !== 0) {
        throw new RuntimeException('Indice unico de CNPJ ausente.');
    }
    $check = $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_restaurantes_cnpj'
SQL)->fetchColumn();
    if ((int) $check !== 1) {
        throw new RuntimeException('CHECK chk_restaurantes_cnpj ausente.');
    }
    echo "restaurantes.cnpj confirmado: CHAR(14), unico e com CHECK de 14 digitos.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao de restaurantes.cnpj: ' . get_class($exception) . "\n");
    exit(1);
}
