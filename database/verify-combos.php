<?php

declare(strict_types=1);

// Conferencia read-only dos combos (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-combos.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ([
        'combos' => ['id_produto' => 'bigint unsigned', 'id_restaurante' => 'bigint unsigned', 'maximo' => 'tinyint unsigned'],
        'combo_itens' => ['id_combo' => 'bigint unsigned', 'id_produto' => 'bigint unsigned', 'quantidade' => 'tinyint unsigned'],
        'combo_opcoes' => ['id_complemento' => 'bigint unsigned', 'id_produto' => 'bigint unsigned', 'maximo' => 'tinyint unsigned'],
        'pedido_itens' => ['combo_lote' => 'char(16)', 'combo_nome' => 'varchar(150)'],
    ] as $tabela => $colunas) {
        $q->execute([$tabela]);
        $atual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($colunas as $nome => $tipo) {
            if (($atual[$nome] ?? null) !== $tipo) {
                throw new RuntimeException("Coluna incorreta: $tabela.$nome");
            }
        }
    }
    $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('combos', 'combo_itens', 'combo_opcoes')")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['fk_combos_produto', 'fk_combo_itens_combo', 'fk_combo_itens_produto', 'fk_combo_opcoes_complemento', 'fk_combo_opcoes_produto'] as $fk) {
        if (!in_array($fk, $fks, true)) {
            throw new RuntimeException("Falta a chave $fk.");
        }
    }
    echo "Combos: tabelas confirmadas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Combos: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
