<?php

declare(strict_types=1);

// Migrations versionadas, somente CLI. Formato YYYYMMDDHHMMSS_nome.php
// retornando callable(PDO $db): void.
// IMPORTANTE: DDL no MySQL causa commits implicitos. Backup antes de aplicar.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = $argv[1] ?? '--status';
if (!in_array($mode, ['--status', '--apply'], true)) {
    fwrite(STDERR, "Uso: php database/migrate.php --status|--apply\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    if (preg_match('/^\d{14}_[a-z0-9_]+\.php$/', basename($file)) !== 1) {
        fwrite(STDERR, 'Nome invalido de migration: ' . basename($file) . "\n");
        exit(1);
    }
}

try {
    $db = \Cardapim\Core\Database::connect();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $lock = $db->query("SELECT GET_LOCK('cardapim_schema_migrations', 30)")->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Nao foi possivel obter lock de migrations.');
    }

    try {
        $applied = $db->query('SELECT filename, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pending = 0;
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if (isset($applied[$name])) {
                if (!hash_equals((string) $applied[$name], $checksum)) {
                    throw new RuntimeException("Migration aplicada foi alterada: $name");
                }
                echo "OK $name\n";
                continue;
            }

            ++$pending;
            if ($mode === '--status') {
                echo "PENDENTE $name\n";
                continue;
            }

            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration precisa retornar callable: $name");
            }

            // Nao envolver DDL em transacao: o MySQL executa commit implicito.
            $migration($db);
            $insert = $db->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
            $insert->execute([$name, $checksum]);
            echo "APLICADA $name\n";
        }
        echo $pending === 0 ? "Nenhuma migration pendente.\n" : "Total pendente: $pending\n";
    } finally {
        $db->query("SELECT RELEASE_LOCK('cardapim_schema_migrations')");
    }
} catch (Throwable $error) {
    // Nao expor senhas ou DSNs completos nos logs.
    fwrite(STDERR, 'Falha na migration: ' . get_class($error) . "\n");
    exit(1);
}
