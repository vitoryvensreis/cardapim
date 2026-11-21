<?php

declare(strict_types=1);

// Conferencia read-only do ponto "Garcom: ao lancar itens" (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-impressao-lancamento.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $clausula = (string) $db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME = 'chk_impressao_pontos_ponto'")->fetchColumn();
    foreach (['garcom_lancar', 'producao', 'delivery_novo', 'caixa_conta'] as $ponto) {
        if (!str_contains($clausula, $ponto)) {
            throw new RuntimeException("chk_impressao_pontos_ponto sem '$ponto'.");
        }
    }
    echo "Impressao ao lancar: ponto do garcom confirmado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Impressao ao lancar: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
