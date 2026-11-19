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
    echo "Dispositivos e balancas confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Falha no schema Desktop: " . $e->getMessage() . "\n");
    exit(1);
}
