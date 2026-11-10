<?php

declare(strict_types=1);

// Somente leitura: confirma tabelas, unidades, FKs compostas e CHECKs do cardapio/estoque.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-cardapio-estoque.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $tabelas = $db->query(<<<'SQL'
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('categorias', 'insumos', 'produtos', 'produto_insumos')
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($tabelas) !== 4) {
        throw new RuntimeException('Tabelas do cardapio/estoque incompletas.');
    }

    $unidade = $db->query(<<<'SQL'
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos' AND COLUMN_NAME = 'unidade'
SQL)->fetchColumn();
    if ($unidade !== "enum('un','kg','l')") {
        throw new RuntimeException('insumos.unidade deve aceitar un, kg e l.');
    }

    $decimais = $db->query(<<<'SQL'
SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME, '=', NUMERIC_SCALE) FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'decimal'
  AND ((TABLE_NAME = 'insumos' AND COLUMN_NAME = 'estoque_atual') OR (TABLE_NAME = 'produto_insumos' AND COLUMN_NAME = 'quantidade'))
ORDER BY TABLE_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if ($decimais !== ['insumos.estoque_atual=3', 'produto_insumos.quantidade=3']) {
        throw new RuntimeException('Quantidades devem ter 3 casas decimais.');
    }

    $fks = $db->query(<<<'SQL'
SELECT CONSTRAINT_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS colunas
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
  AND CONSTRAINT_NAME IN ('fk_produtos_categoria', 'fk_produto_insumos_produto', 'fk_produto_insumos_insumo')
GROUP BY CONSTRAINT_NAME
SQL)->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($fks['fk_produtos_categoria'] ?? '') !== 'id_categoria,id_restaurante'
        || ($fks['fk_produto_insumos_produto'] ?? '') !== 'id_produto,id_restaurante'
        || ($fks['fk_produto_insumos_insumo'] ?? '') !== 'id_insumo,id_restaurante') {
        throw new RuntimeException('Chaves estrangeiras compostas por restaurante ausentes.');
    }

    $checks = (int) $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('chk_insumos_estoque_atual', 'chk_produto_insumos_quantidade', 'chk_produtos_preco')
SQL)->fetchColumn();
    if ($checks !== 3) {
        throw new RuntimeException('CHECKs de estoque, quantidade e preco ausentes.');
    }
    echo "Cardapio e estoque confirmados: 4 tabelas, unidades un/kg/l, FKs por restaurante e CHECKs.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do cardapio/estoque: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
