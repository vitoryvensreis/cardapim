<?php

declare(strict_types=1);

// Conferencia read-only do Meio a Meio (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-meio-a-meio.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $esperado = [
        'produto_meio_a_meio' => [
            'id_produto' => 'bigint unsigned', 'id_restaurante' => 'bigint unsigned', 'minimo' => 'tinyint unsigned', 'maximo' => 'tinyint unsigned',
            'regra' => "enum('fracionado','maior','personalizado')", 'id_categoria' => 'bigint unsigned',
        ],
        'produto_meio_sabores' => ['id_produto' => 'bigint unsigned', 'id_sabor' => 'bigint unsigned', 'id_restaurante' => 'bigint unsigned'],
    ];
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($esperado as $tabela => $colunas) {
        $q->execute([$tabela]);
        $reais = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($colunas as $coluna => $tipo) {
            if (($reais[$coluna] ?? null) !== $tipo) {
                throw new RuntimeException("Falta $tabela.$coluna ($tipo).");
            }
        }
    }
    echo "Meio a Meio confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Meio a Meio: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
