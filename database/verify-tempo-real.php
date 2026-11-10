<?php

declare(strict_types=1);

// Somente leitura: confirma a tabela outbox_eventos do tempo real (colunas, indices e regras).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-tempo-real.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['outbox_eventos']);
    $colunas = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $colunas[$c['COLUMN_NAME']] = [$c['COLUMN_TYPE'], $c['IS_NULLABLE']];
    }
    $esperado = [
        'id' => ['bigint unsigned', 'NO'],
        'id_restaurante' => ['bigint unsigned', 'YES'],
        'canal' => ['varchar(30)', 'NO'],
        'tipo' => ['varchar(80)', 'NO'],
        'dados' => ['text', 'NO'],
        'criado_em' => ['datetime', 'NO'],
        'publicado_em' => ['datetime', 'YES'],
    ];
    foreach ($esperado as $nome => $tipo) {
        if (($colunas[$nome] ?? null) !== $tipo) {
            throw new RuntimeException('Coluna outbox_eventos.' . $nome . ' incorreta.');
        }
    }
    $indices = $db->query(<<<'SQL'
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox_eventos' AND INDEX_NAME IN ('idx_outbox_restaurante', 'idx_outbox_criado')
GROUP BY INDEX_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($indices) !== 2) {
        throw new RuntimeException('Indices da outbox ausentes.');
    }
    $regras = (int) $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox_eventos'
  AND CONSTRAINT_NAME IN ('fk_outbox_restaurante', 'chk_outbox_canal', 'chk_outbox_tipo')
SQL)->fetchColumn();
    if ($regras !== 3) {
        throw new RuntimeException('Regras (FK/CHECK) da outbox ausentes.');
    }
    echo "Tempo real confirmado: tabela outbox_eventos, indices e regras.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do tempo real: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
