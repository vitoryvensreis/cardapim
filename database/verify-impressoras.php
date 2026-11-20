<?php

declare(strict_types=1);

// Conferencia read-only das varias impressoras do Agent e dos pontos de impressao (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-impressoras.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $esperado = [
        'impressoras' => ['id_restaurante' => 'bigint unsigned', 'id_dispositivo' => 'bigint unsigned', 'chave' => 'varchar(40)', 'apelido' => 'varchar(40)', 'ativo' => 'tinyint(1)'],
        'impressao_pontos' => ['id_restaurante' => 'bigint unsigned', 'ponto' => 'varchar(20)', 'id_impressora' => 'bigint unsigned', 'padrao' => 'tinyint(1)'],
        'impressoes' => ['id_impressora' => 'bigint unsigned'],
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
    $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('impressoras', 'impressao_pontos', 'impressoes')")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['fk_impressoras_dispositivo', 'fk_impressao_pontos_impressora', 'fk_impressoes_impressora'] as $fk) {
        if (!in_array($fk, $fks, true)) {
            throw new RuntimeException("Falta a chave $fk.");
        }
    }
    $db->query("EXPLAIN SELECT im.id, COALESCE(i.chave, '') FROM impressoes im LEFT JOIN impressoras i ON i.id = im.id_impressora
        WHERE im.id_restaurante = 0 AND (im.id_impressora IS NULL OR (i.id_dispositivo = 0 AND i.ativo = 1)) AND im.entregue_em IS NULL");
    echo "Impressoras: lista do Agent e pontos de impressao confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Impressoras: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
