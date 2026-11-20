<?php

declare(strict_types=1);

// Conferencia read-only da impressao por produto / vias de producao (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-impressao-produto.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach (['produto_impressoras' => ['id_restaurante' => 'bigint unsigned', 'id_produto' => 'bigint unsigned', 'id_impressora' => 'bigint unsigned'],
              'impressoes' => ['lote' => 'char(16)']] as $tabela => $colunas) {
        $q->execute([$tabela]);
        $atual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($colunas as $nome => $tipo) {
            if (($atual[$nome] ?? null) !== $tipo) {
                throw new RuntimeException("Coluna incorreta: $tabela.$nome");
            }
        }
    }
    $checks = $db->query("SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME IN ('chk_impressao_pontos_ponto', 'chk_impressoes_tipo')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['chk_impressao_pontos_ponto', 'chk_impressoes_tipo'] as $nome) {
        if (!str_contains((string) ($checks[$nome] ?? ''), 'producao')) {
            throw new RuntimeException("$nome sem 'producao'.");
        }
    }
    $fks = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'produto_impressoras'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['fk_produto_impressoras_produto', 'fk_produto_impressoras_impressora'] as $fk) {
        if (!in_array($fk, $fks, true)) {
            throw new RuntimeException("Falta a chave $fk.");
        }
    }
    echo "Impressao por produto: vias de producao confirmadas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Impressao por produto: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
