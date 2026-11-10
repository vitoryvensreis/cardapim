<?php

declare(strict_types=1);

// Somente leitura: verifica colunas, constraints e relacionamentos.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-user-schema.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $query = $db->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $query->execute(['user']);
    $columns = [];
    foreach ($query->fetchAll() as $column) {
        $columns[$column['COLUMN_NAME']] = $column;
    }

    $types = [
        'id' => 'bigint',
        'id_restaurante' => 'bigint',
        'cargo' => 'enum',
        'usuario' => 'varchar',
        'email' => 'varchar',
        'senha_hash' => 'varchar',
        'nome' => 'varchar',
        'sobrenome' => 'varchar',
        'cpf' => 'char',
        'whatsapp' => 'varchar',
        'ultimo_acesso' => 'datetime',
        'ultimo_codigo_hash' => 'varchar',
        'ultimo_codigo_expira_em' => 'datetime',
        'email_verificado_em' => 'datetime',
        'ativo' => 'tinyint',
        'criado_em' => 'datetime',
        'atualizado_em' => 'datetime',
    ];
    if (count($columns) !== count($types)) {
        throw new RuntimeException('Quantidade de colunas diferente do esperado.');
    }
    foreach ($types as $name => $type) {
        if (($columns[$name]['DATA_TYPE'] ?? null) !== $type) {
            throw new RuntimeException("Coluna user.$name com tipo diferente do previsto.");
        }
    }
    foreach (['id_restaurante', 'cpf', 'whatsapp', 'ultimo_acesso', 'ultimo_codigo_hash',
              'ultimo_codigo_expira_em', 'email_verificado_em'] as $name) {
        if ($columns[$name]['IS_NULLABLE'] !== 'YES') {
            throw new RuntimeException("Coluna $name deveria aceitar NULL.");
        }
    }
    if ($columns['id']['IS_NULLABLE'] !== 'NO'
        || !str_contains($columns['id']['EXTRA'], 'auto_increment')
        || $columns['senha_hash']['IS_NULLABLE'] !== 'NO'
        || $columns['ativo']['IS_NULLABLE'] !== 'NO'
        || (string) $columns['ativo']['COLUMN_DEFAULT'] !== '1') {
        throw new RuntimeException('Regras basicas de user incorretas.');
    }

    $fk = $db->query(<<<'SQL'
SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME,
       CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('fk_user_restaurante', 'fk_log_user_usuario')
SQL)->fetchAll();
    $relations = [];
    foreach ($fk as $item) {
        $relations[$item['CONSTRAINT_NAME']] = $item;
    }
    if (count($relations) !== 2
        || $relations['fk_user_restaurante']['TABLE_NAME'] !== 'user'
        || $relations['fk_user_restaurante']['COLUMN_NAME'] !== 'id_restaurante'
        || $relations['fk_user_restaurante']['REFERENCED_TABLE_NAME'] !== 'restaurantes'
        || $relations['fk_user_restaurante']['REFERENCED_COLUMN_NAME'] !== 'id'
        || $relations['fk_log_user_usuario']['TABLE_NAME'] !== 'log_user'
        || $relations['fk_log_user_usuario']['COLUMN_NAME'] !== 'id_user'
        || $relations['fk_log_user_usuario']['REFERENCED_TABLE_NAME'] !== 'user'
        || $relations['fk_log_user_usuario']['REFERENCED_COLUMN_NAME'] !== 'id') {
        throw new RuntimeException('Chaves estrangeiras incompletas.');
    }
    $checks = $db->query(<<<'SQL'
SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('chk_user_escopo', 'chk_user_ativo', 'chk_user_cpf')
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($checks) !== 3) {
        throw new RuntimeException('Restricoes de escopo/ativacao/CPF ausentes.');
    }
    $cpfIndex = $db->query(<<<'SQL'
SELECT NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user'
  AND INDEX_NAME = 'uq_user_cpf' AND COLUMN_NAME = 'cpf'
SQL)->fetchColumn();
    if ($cpfIndex === false || (int) $cpfIndex !== 0) {
        throw new RuntimeException('Indice unico de CPF ausente.');
    }
    $db->query('SELECT id FROM `user` LIMIT 1');
    echo "Schema user confirmado: cargos, restaurante, CPF unico e FK log_user.id_user.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do schema user: ' . get_class($exception) . "\n");
    exit(1);
}
