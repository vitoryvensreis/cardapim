<?php

declare(strict_types=1);

// Somente leitura: confirma planos (vitalicio/convite), restaurante inadimplente, assinaturas no Asaas e faturas.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-assinaturas.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $colunas = static function (string $tabela) use ($db): array {
        $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabela]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $p = $colunas('planos');
    if (!str_contains((string) ($p['periodo'] ?? ''), "'vitalicio'") || ($p['codigo_convite'] ?? '') !== 'char(24)') {
        throw new RuntimeException('planos sem vitalicio ou codigo_convite.');
    }
    $r = $colunas('restaurantes');
    if (!str_contains((string) ($r['status_rest'] ?? ''), "'inadimplente'")) {
        throw new RuntimeException('restaurantes.status_rest sem inadimplente.');
    }
    $a = $colunas('assinaturas');
    foreach (['asaas_cliente_id', 'asaas_assinatura_id', 'cobranca_erro', 'ambiente'] as $c) {
        if (!isset($a[$c])) {
            throw new RuntimeException('assinaturas.' . $c . ' ausente.');
        }
    }
    if (!str_contains((string) $a['status'], "'aguardando'")) {
        throw new RuntimeException('assinaturas.status sem aguardando.');
    }
    $f = $colunas('assinatura_faturas');
    foreach (['asaas_id' => 'varchar(60)', 'valor' => 'decimal(10,2)', 'vencimento' => 'date', 'link_fatura' => 'varchar(500)'] as $c => $tipo) {
        if (($f[$c] ?? '') !== $tipo) {
            throw new RuntimeException('assinatura_faturas.' . $c . ' incorreta ou ausente.');
        }
    }
    echo "Assinaturas confirmadas: planos vitalicio/convite, inadimplente, Asaas nas assinaturas e assinatura_faturas.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao das assinaturas: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
