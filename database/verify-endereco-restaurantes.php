<?php

declare(strict_types=1);

// Somente leitura: confirma colunas e CHECKs de endereco em restaurantes.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-endereco-restaurantes.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $colunas = $db->query(<<<'SQL'
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'
  AND COLUMN_NAME IN ('cep','logradouro','numero','complemento','bairro','cidade','estado','ponto_referencia','latitude','longitude')
SQL)->fetchAll(PDO::FETCH_UNIQUE);
    $esperado = [
        'cep' => 'char(8)', 'logradouro' => 'varchar(180)', 'numero' => 'varchar(20)', 'complemento' => 'varchar(120)',
        'bairro' => 'varchar(120)', 'cidade' => 'varchar(120)', 'estado' => 'char(2)', 'ponto_referencia' => 'varchar(180)',
        'latitude' => 'decimal(10,7)', 'longitude' => 'decimal(10,7)',
    ];
    foreach ($esperado as $coluna => $tipo) {
        if (($colunas[$coluna]['COLUMN_TYPE'] ?? null) !== $tipo || $colunas[$coluna]['IS_NULLABLE'] !== 'YES') {
            throw new RuntimeException("restaurantes.$coluna deve ser $tipo NULL.");
        }
    }
    $checks = (int) $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('chk_restaurantes_cep', 'chk_restaurantes_estado', 'chk_restaurantes_coordenadas')
SQL)->fetchColumn();
    if ($checks !== 3) {
        throw new RuntimeException('CHECKs de CEP, UF e coordenadas ausentes.');
    }
    echo "Endereco de restaurantes confirmado: 10 colunas e CHECKs de CEP, UF e coordenadas.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do endereco: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
