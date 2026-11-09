<?php

declare(strict_types=1);

// Bootstrap inicial, somente CLI; o banco deve estar vazio ou já possuir o schema esperado.
// Não altera bancos com tabelas de outros sistemas nem recria esquemas parciais.

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Uso: php8.4 database/initialize-schema.php --apply\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $databaseName = $db->query('SELECT DATABASE()')->fetchColumn();
    $isCiDatabase = getenv('CI') === 'true' && $databaseName === 'cardapim_ci';
    if ($databaseName !== 'cardapim' && !$isCiDatabase) {
        throw new RuntimeException('Database inesperado. Inicializacao interrompida.');
    }

    $expectedFiles = [
        '20261109232000_create_planos.php',
        '20261109232100_create_restaurantes.php',
    ];
    $availableFiles = array_map('basename', glob(__DIR__ . '/migrations/*.php') ?: []);
    sort($availableFiles, SORT_STRING);
    if ($availableFiles !== $expectedFiles) {
        throw new RuntimeException('Conjunto de migrations diferente do schema inicial esperado.');
    }

    $tables = $db->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);

    $unexpected = array_diff($tables, ['schema_migrations', 'planos', 'restaurantes']);
    if ($unexpected !== []) {
        throw new RuntimeException('Banco contem outras tabelas. Nao aplicar bootstrap automatico.');
    }

    $hasPlans = in_array('planos', $tables, true);
    $hasRestaurants = in_array('restaurantes', $tables, true);

    if ($hasPlans !== $hasRestaurants) {
        throw new RuntimeException('Schema parcial encontrado. Requer analise manual.');
    }

    if (!$hasPlans) {
        if (in_array('schema_migrations', $tables, true)) {
            $alreadyApplied = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            if ($alreadyApplied !== 0) {
                throw new RuntimeException('Historico de migrations nao esta vazio.');
            }
        }

        // Apenas nesta situacao, sem tabelas operacionais nem dados existentes, aplicar DDL.
        echo "Banco inicial vazio: aplicando duas migrations versionadas.\n";
        $argv = [__DIR__ . '/migrate.php', '--apply'];
        require __DIR__ . '/migrate.php';
    } else {
        echo "Tabelas existentes: verificando schema sem modificar dados.\n";
    }

    foreach ([
        'planos' => ['id', 'nome', 'descricao', 'valor', 'periodo', 'ativo', 'created_at', 'updated_at'],
        'restaurantes' => ['id', 'nome', 'slug', 'telefone', 'email', 'cnpj', 'plano_id', 'status_rest', 'logo_path', 'created_at', 'updated_at'],
    ] as $table => $columns) {
        $statement = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        $found = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($columns, $found) !== []) {
            throw new RuntimeException("Colunas esperadas ausentes na tabela $table.");
        }
    }

    $relation = $db->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'
         AND COLUMN_NAME = 'plano_id' AND REFERENCED_TABLE_NAME = 'planos'
         AND REFERENCED_COLUMN_NAME = 'id'"
    )->fetchColumn();
    if ((int) $relation !== 1) {
        throw new RuntimeException('Chave estrangeira restaurantes.plano_id ausente.');
    }

    if (in_array('schema_migrations', $tables, true) || !$hasPlans) {
        $db->query('SELECT 1')->fetchColumn();
    }
    echo "Esquema inicial verificado: planos e restaurantes vinculados.\n";
} catch (Throwable $error) {
    // Nao imprimir mensagens PDO potencialmente sensiveis.
    fwrite(STDERR, 'Inicializacao nao concluida: ' . ($error instanceof PDOException ? 'erro de banco' : $error->getMessage()) . "\n");
    exit(1);
}
