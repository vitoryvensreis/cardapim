<?php

declare(strict_types=1);

// Somente leitura: confirma a tabela do "manter conectado".
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-sessoes-lembradas.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $colunas = $db->query(<<<'SQL'
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessoes_lembradas'
SQL)->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($colunas['seletor'] ?? '') !== 'char(24)' || ($colunas['validador_hash'] ?? '') !== 'char(64)'
        || !isset($colunas['id_user'], $colunas['expira_em'], $colunas['navegador'])) {
        throw new RuntimeException('Colunas de user_sessoes_lembradas incorretas.');
    }
    $fk = $db->query(<<<'SQL'
SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_sessoes_lembradas_user'
SQL)->fetchColumn();
    $unico = $db->query(<<<'SQL'
SELECT NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessoes_lembradas' AND INDEX_NAME = 'uq_sessoes_lembradas_seletor'
SQL)->fetchColumn();
    if ($fk !== 'CASCADE' || $unico === false || (int) $unico !== 0) {
        throw new RuntimeException('FK com user ou seletor unico ausente.');
    }
    echo "Manter conectado confirmado: user_sessoes_lembradas com seletor unico e FK para user.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do manter conectado: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
