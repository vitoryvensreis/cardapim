<?php

declare(strict_types=1);

// Conferencia read-only da taxa por forma de pagamento (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-taxas-pagamento.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $col = static function (string $tabela, string $coluna) use ($db): ?array {
        $q = $db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $q->execute([$tabela, $coluna]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    };
    $t = $col('pedidos', 'taxa_pagamento');
    if ($t === null || $t['COLUMN_TYPE'] !== 'decimal(10,2)' || $t['IS_NULLABLE'] !== 'NO' || (string) $t['COLUMN_DEFAULT'] !== '0.00') {
        throw new RuntimeException('Falta pedidos.taxa_pagamento (DECIMAL(10,2) NOT NULL DEFAULT 0.00).');
    }
    if ($col('pedidos', 'taxa_pagamento_rotulo') === null) {
        throw new RuntimeException('Falta pedidos.taxa_pagamento_rotulo.');
    }
    $q = $db->prepare("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_pedidos_valores'");
    $q->execute();
    if (!str_contains((string) $q->fetchColumn(), 'taxa_pagamento')) {
        throw new RuntimeException('O CHECK chk_pedidos_valores nao inclui taxa_pagamento.');
    }
    foreach (['forma', 'tipo', 'valor'] as $c) {
        if ($col('restaurante_taxas_pagamento', $c) === null) {
            throw new RuntimeException("Falta restaurante_taxas_pagamento.$c.");
        }
    }
    echo "Taxas por forma de pagamento confirmadas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Taxas de pagamento: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
