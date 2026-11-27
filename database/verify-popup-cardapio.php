<?php

declare(strict_types=1);

// Conferencia read-only do popup do cardapio (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-popup-cardapio.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $esperado = [
        'ativo' => 'tinyint(1)', 'tipo' => "enum('imagem','texto')", 'imagem_path' => 'varchar(120)', 'titulo' => 'varchar(60)',
        'texto' => 'varchar(300)', 'inicio' => 'date', 'fim' => 'date', 'versao' => 'int unsigned',
    ];
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['restaurante_popup']);
    $colunas = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($esperado as $coluna => $tipo) {
        if (($colunas[$coluna] ?? null) !== $tipo) {
            throw new RuntimeException("Falta restaurante_popup.$coluna ($tipo).");
        }
    }
    echo "Popup do cardapio confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Popup do cardapio: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
