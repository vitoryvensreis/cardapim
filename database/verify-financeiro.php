<?php

declare(strict_types=1);

// Somente leitura: confirma a tabela de contas a pagar e a receber (aba Financeiro).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-financeiro.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['contas_financeiras']);
    $c = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['tipo' => "enum('pagar','receber')", 'valor' => 'decimal(10,2)', 'vencimento' => 'date', 'status' => "enum('aberta','paga')", 'pago_em' => 'date'] as $nome => $tipo) {
        if (($c[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna contas_financeiras.' . $nome . ' incorreta ou ausente.');
        }
    }
    echo "Financeiro confirmado: contas_financeiras (pagar/receber, DECIMAL, vencimento e baixa).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do financeiro: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
