<?php

declare(strict_types=1);

// Somente leitura: confirma as regras de cobranca das assinaturas (Asaas da plataforma).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-cobranca.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute(['plataforma_cobranca']);
    $c = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['formas' => 'varchar(40)', 'vencimento_dias' => 'tinyint unsigned', 'multa_percentual' => 'decimal(5,2)', 'juros_mensal' => 'decimal(5,2)',
        'desconto_percentual' => 'decimal(5,2)', 'tolerancia_dias' => 'tinyint unsigned', 'avisos_asaas' => 'tinyint(1)'] as $nome => $tipo) {
        if (($c[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna plataforma_cobranca.' . $nome . ' incorreta ou ausente.');
        }
    }
    $linhas = (int) $db->query('SELECT COUNT(*) FROM plataforma_cobranca WHERE id = 1')->fetchColumn();
    if ($linhas !== 1) {
        throw new RuntimeException('Falta a linha de regras padrao (id = 1).');
    }
    echo "Cobranca confirmada: plataforma_cobranca com as regras padrao.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao da cobranca: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
