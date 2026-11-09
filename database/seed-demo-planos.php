<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--apply', '--verify'], true)) {
    fwrite(STDERR, "Uso: php8.4 database/seed-demo-planos.php --apply|--verify\n");
    exit(2);
}
$mode = $argv[1];

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $plans = [
        ['Demonstração Mensal', 'mensal', '99.90'],
        ['Demonstração Semestral', 'semestral', '499.90'],
        ['Demonstração Anual', 'anual', '899.90'],
    ];

    if ($mode === '--apply') {
        $db->beginTransaction();
        try {
            $seeder = require __DIR__ . '/seeders/demo_planos.php';
            $seeder($db);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    $get = $db->prepare('SELECT valor, ativo FROM planos WHERE nome = ? AND periodo = ?');
    foreach ($plans as [$name, $period, $expectedPrice]) {
        $get->execute([$name, $period]);
        $plan = $get->fetch();
        if ($plan === false || (string) $plan['valor'] !== $expectedPrice || (int) $plan['ativo'] !== 1) {
            throw new RuntimeException("Plano de demonstracao indisponivel ou modificado: $period");
        }
    }

    $dateType = $db->query(<<<'SQL'
SELECT DATA_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'restaurantes'
    AND COLUMN_NAME = 'ultimo_pagamento'
SQL)->fetchColumn();

    if ($dateType !== 'date') {
        throw new RuntimeException('Coluna restaurantes.ultimo_pagamento deve ser DATE.');
    }
    echo "Planos ficticios verificados: mensal, semestral e anual. Ultimo pagamento: DATE NULL.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha na verificacao dos planos ficticios: ' . get_class($error) . "\n");
    exit(1);
}
