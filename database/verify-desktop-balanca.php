<?php

declare(strict_types=1);

// Conferencia read-only do schema do agente e das balancas (CI).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php database/verify-desktop-balanca.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $expected = [
        'desktop_agent_devices' => [
            'id_restaurante' => 'bigint unsigned', 'id_user' => 'bigint unsigned',
            'token_hash' => 'char(64)', 'expira_em' => 'datetime',
            'revogado_em' => 'datetime',
        ],
        'desktop_agent_pesagens' => [
            'id_dispositivo' => 'bigint unsigned',
            'id_restaurante' => 'bigint unsigned',
            'peso_kg' => 'decimal(5,3)',
            'estavel' => 'tinyint(1)',
            'atualizado_em' => 'datetime(6)',
        ],
    ];
    $q = $db->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    foreach ($expected as $table => $fields) {
        $q->execute([$table]);
        $actual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($fields as $name => $type) {
            if (($actual[$name] ?? null) !== $type) {
                throw new RuntimeException("Coluna incorreta: $table.$name");
            }
        }
    }
    $q = $db->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = 'fk_desktop_pesagens_device'"
    );
    if ($q->fetchColumn() !== 'fk_desktop_pesagens_device') {
        throw new RuntimeException('FK composta da pesagem ausente.');
    }
    // Valida as consultas reais da API ainda no passo de schema:
    // se houver uma coluna/alias inválido, o CI mostra o SQLSTATE antes
    // de iniciar os testes HTTP e sem expor credenciais ou dados de usuários.
    $db->query("EXPLAIN SELECT d.*, u.ativo, u.cargo, u.excluido_em,
                      r.status_rest AS restaurante_status
                 FROM desktop_agent_devices d
                 JOIN `user` u ON u.id = d.id_user
                    AND u.id_restaurante = d.id_restaurante
                 JOIN restaurantes r ON r.id = d.id_restaurante
                WHERE d.token_hash = 'hash_inexistente'
                    AND d.revogado_em IS NULL AND d.expira_em > NOW()
                LIMIT 1");
    $db->query("EXPLAIN SELECT d.id, d.nome,
                      CASE WHEN p.atualizado_em >= NOW(6) - INTERVAL 3 SECOND
                           AND p.estavel = 1 THEN p.peso_kg ELSE NULL END AS peso_kg
                 FROM desktop_agent_devices d
                 JOIN `user` u ON u.id = d.id_user
                    AND u.id_restaurante = d.id_restaurante
                 LEFT JOIN desktop_agent_pesagens p ON p.id_dispositivo = d.id
                    AND p.id_restaurante = d.id_restaurante
                WHERE d.id_restaurante = 0 AND d.revogado_em IS NULL
                    AND d.expira_em > NOW() AND u.ativo = 1
                    AND u.excluido_em IS NULL
                LIMIT 50");
    echo "Dispositivos, balancas e consultas SQL confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Falha no schema Desktop: " . $e->getMessage() . "\n");
    exit(1);
}
