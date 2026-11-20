<?php

declare(strict_types=1);

// Conferencia read-only da fila de impressao do Agent (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-impressoes.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $esperado = [
        'impressoes' => [
            'id_restaurante' => 'bigint unsigned', 'id_pedido' => 'bigint unsigned', 'tipo' => 'varchar(20)',
            'titulo' => 'varchar(80)', 'conteudo' => 'mediumtext', 'ate_item' => 'bigint unsigned',
            'id_dispositivo' => 'bigint unsigned', 'entregue_em' => 'datetime', 'resultado' => 'varchar(10)',
        ],
        'desktop_agent_devices' => ['impressao_em' => 'datetime'],
    ];
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($esperado as $tabela => $colunas) {
        $q->execute([$tabela]);
        $atual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($colunas as $nome => $tipo) {
            if (($atual[$nome] ?? null) !== $tipo) {
                throw new RuntimeException("Coluna incorreta: $tabela.$nome");
            }
        }
    }
    $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'impressoes'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['fk_impressoes_restaurante', 'fk_impressoes_usuario', 'fk_impressoes_dispositivo'] as $fk) {
        if (!in_array($fk, $fks, true)) {
            throw new RuntimeException("Falta a chave $fk.");
        }
    }
    // As consultas reais da fila (sem dados): erro de coluna aparece aqui, antes dos testes HTTP.
    $db->query("EXPLAIN SELECT id, titulo, conteudo FROM impressoes WHERE id_restaurante = 0 AND entregue_em IS NULL
        AND criado_em >= NOW() - INTERVAL 120 SECOND ORDER BY id LIMIT 10");
    $db->query("EXPLAIN SELECT 1 FROM desktop_agent_devices WHERE id_restaurante = 0 AND revogado_em IS NULL
        AND expira_em > NOW() AND impressao_em >= NOW() - INTERVAL 30 SECOND LIMIT 1");
    echo "Impressoes: fila do Agent confirmada.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Impressoes: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
