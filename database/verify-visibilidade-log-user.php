<?php

declare(strict_types=1);

// Verificação exclusivamente de leitura, apropriada para CI e produção.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-visibilidade-log-user.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $query = $db->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $query->execute(['planos']);
    $columns = [];
    foreach ($query->fetchAll() as $col) {
        $columns[$col['COLUMN_NAME']] = $col;
    }
    $visibility = $columns['visibilidade'] ?? null;
    if ($visibility === null || $visibility['DATA_TYPE'] !== 'tinyint'
        || (string) $visibility['COLUMN_DEFAULT'] !== '1' || $visibility['IS_NULLABLE'] !== 'NO') {
        throw new RuntimeException('planos.visibilidade nao corresponde ao schema esperado.');
    }

    $check = $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_planos_visibilidade'
SQL)->fetchColumn();
    if ((int) $check !== 1) {
        throw new RuntimeException('Restricao de visibilidade ausente.');
    }

    $query->execute(['log_user']);
    $log = [];
    foreach ($query->fetchAll() as $col) {
        $log[$col['COLUMN_NAME']] = $col;
    }
    $expected = [
        'id' => 'bigint',
        'titulo' => 'varchar',
        'descricao' => 'text',
        'id_user' => 'bigint',
        'data_hora' => 'datetime',
        'ip' => 'varchar',
    ];
    if (count($log) !== count($expected)) {
        throw new RuntimeException('log_user possui quantidade inesperada de colunas.');
    }
    foreach ($expected as $field => $type) {
        if (($log[$field]['DATA_TYPE'] ?? null) !== $type) {
            throw new RuntimeException("Campo inesperado na tabela log_user: $field.");
        }
    }
    if ($log['data_hora']['IS_NULLABLE'] !== 'NO' || $log['titulo']['IS_NULLABLE'] !== 'NO'
        || $log['descricao']['IS_NULLABLE'] !== 'NO' || $log['id']['IS_NULLABLE'] !== 'NO'
        || $log['id_user']['IS_NULLABLE'] !== 'YES' || $log['ip']['IS_NULLABLE'] !== 'YES') {
        throw new RuntimeException('Nulabilidade de log_user incorreta.');
    }
    if (!str_contains($log['id']['EXTRA'], 'auto_increment')) {
        throw new RuntimeException('log_user.id deve ser AUTO_INCREMENT.');
    }
    $db->query('SELECT 1 FROM log_user LIMIT 1')->fetchColumn();
    echo "Schema verificado: planos.visibilidade DEFAULT 1 e log_user com seis colunas.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha na verificacao de schema: ' . get_class($error) . "\n");
    exit(1);
}
