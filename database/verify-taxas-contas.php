<?php

declare(strict_types=1);

// Conferencia read-only da taxa por forma de pagamento nos fechamentos de conta (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-taxas-contas.php --verify\n");
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
    foreach (['pedido_pagamentos', 'comanda_pix'] as $tabela) {
        $t = $col($tabela, 'taxa');
        if ($t === null || $t['COLUMN_TYPE'] !== 'decimal(10,2)' || $t['IS_NULLABLE'] !== 'NO' || (string) $t['COLUMN_DEFAULT'] !== '0.00') {
            throw new RuntimeException("Falta $tabela.taxa (DECIMAL(10,2) NOT NULL DEFAULT 0.00).");
        }
        if ($col($tabela, 'taxa_rotulo') === null) {
            throw new RuntimeException("Falta $tabela.taxa_rotulo.");
        }
    }
    $q = $db->prepare("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_pedido_pagamentos_taxa'");
    $q->execute();
    $check = (string) $q->fetchColumn();
    if (!str_contains($check, 'taxa') || !str_contains($check, 'dinheiro')) {
        throw new RuntimeException('Falta o CHECK chk_pedido_pagamentos_taxa (taxa menor que o valor e nunca em dinheiro).');
    }
    if ($col('pedidos', 'taxa_pagamento') === null) {
        throw new RuntimeException('Falta pedidos.taxa_pagamento (migracao das taxas do cardapio online).');
    }
    echo "Taxas nos fechamentos de conta confirmadas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Taxas nas contas: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
