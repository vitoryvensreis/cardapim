<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-garcom-permissoes.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurante_garcom_permissoes'");
    $colunas = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $colunas[$c['COLUMN_NAME']] = $c;
    }
    if (!isset($colunas['id_restaurante'])) {
        throw new RuntimeException('Falta a tabela restaurante_garcom_permissoes.');
    }
    foreach (['remover_itens', 'cancelar_comanda'] as $campo) {
        $c = $colunas[$campo] ?? null;
        if ($c === null || $c['COLUMN_TYPE'] !== 'tinyint(1)' || $c['IS_NULLABLE'] !== 'NO' || (string) $c['COLUMN_DEFAULT'] !== '0') {
            throw new RuntimeException("Falta $campo (TINYINT(1) NOT NULL DEFAULT 0: bloqueado por padrao).");
        }
    }
    $q = $db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'restaurante_garcom_permissoes' AND CONSTRAINT_NAME = 'fk_garcom_permissoes_restaurante' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    if ((int) $q->fetchColumn() !== 1) {
        throw new RuntimeException('Falta a FK para restaurantes.');
    }
    echo "Permissoes do garcom: tabela, padrao bloqueado e FK confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Permissoes do garcom: falha de verificacao: " . $e->getMessage() . "\n");
    exit(1);
}
