<?php

declare(strict_types=1);

// Conferencia read-only do Pix pela chave do restaurante (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-pix-chave.php --verify\n");
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
    $f = $col('pedidos', 'forma_pagamento');
    if ($f === null || !str_contains((string) $f['COLUMN_TYPE'], "'pix_comprovante'") || !str_contains((string) $f['COLUMN_TYPE'], "'ifood'")) {
        throw new RuntimeException('pedidos.forma_pagamento sem pix_comprovante.');
    }
    if ($col('pedidos', 'comprovante_enviado_em') === null) {
        throw new RuntimeException('Falta pedidos.comprovante_enviado_em.');
    }
    $p = $col('restaurante_entrega_pagamento', 'pag_pix_comprovante');
    if ($p === null || $p['IS_NULLABLE'] !== 'NO' || (string) $p['COLUMN_DEFAULT'] !== '0') {
        throw new RuntimeException('Falta restaurante_entrega_pagamento.pag_pix_comprovante (NOT NULL DEFAULT 0).');
    }
    foreach (['pix_chave_tipo', 'pix_chave', 'pix_recebedor', 'pix_cidade', 'pix_whatsapp'] as $c) {
        if ($col('restaurante_entrega_pagamento', $c) === null) {
            throw new RuntimeException("Falta restaurante_entrega_pagamento.$c.");
        }
    }
    $q = $db->prepare("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_pix_um_por_vez'");
    $q->execute();
    $check = (string) $q->fetchColumn();
    if (!str_contains($check, 'pag_pix_online') || !str_contains($check, 'pag_pix_comprovante')) {
        throw new RuntimeException('Falta o CHECK chk_pix_um_por_vez (Asaas e chave nunca juntos).');
    }
    echo "Pix pela chave confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Pix pela chave: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
