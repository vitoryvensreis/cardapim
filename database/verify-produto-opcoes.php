<?php

declare(strict_types=1);

// Conferencia read-only de "nao imprime", "so presencial" e da impressao de cancelamento (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-produto-opcoes.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['produtos']);
    $atual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['imprime_producao', 'vende_delivery'] as $coluna) {
        if (!in_array($atual[$coluna] ?? null, ['tinyint(1)', 'tinyint'], true)) {
            throw new RuntimeException("Coluna incorreta: produtos.$coluna");
        }
    }
    $clausula = (string) $db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME = 'chk_impressoes_tipo'")->fetchColumn();
    foreach (['cancelamento', 'producao', 'conta'] as $tipo) {
        if (!str_contains($clausula, $tipo)) {
            throw new RuntimeException("chk_impressoes_tipo sem '$tipo'.");
        }
    }
    echo "Produto: nao imprime, so presencial e cancelamento confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Produto: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
