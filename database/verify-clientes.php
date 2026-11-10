<?php

declare(strict_types=1);

// Somente leitura: confirma as tabelas do cliente final (clientes e cliente_sessoes).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-clientes.php --verify\n");
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
    $c = $colunas('clientes');
    foreach (['nome' => 'varchar(80)', 'whatsapp' => 'char(11)', 'cpf' => 'char(11)', 'cep' => 'char(8)', 'estado' => 'char(2)', 'ultimo_acesso' => 'datetime'] as $nome => $tipo) {
        if (($c[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna clientes.' . $nome . ' incorreta.');
        }
    }
    foreach (['logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'ponto_referencia', 'latitude', 'longitude', 'ativo'] as $nome) {
        if (!isset($c[$nome])) {
            throw new RuntimeException('Coluna clientes.' . $nome . ' ausente.');
        }
    }
    $s = $colunas('cliente_sessoes');
    if (($s['seletor'] ?? '') !== 'char(24)' || ($s['validador_hash'] ?? '') !== 'char(64)') {
        throw new RuntimeException('Colunas de cliente_sessoes incorretas.');
    }
    $unicos = $db->query(<<<'SQL'
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
  AND INDEX_NAME IN ('uq_clientes_whatsapp', 'uq_clientes_cpf', 'uq_cliente_sessoes_seletor')
GROUP BY INDEX_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($unicos) !== 3) {
        throw new RuntimeException('Indices unicos dos clientes ausentes.');
    }
    $regras = (int) $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND CONSTRAINT_TYPE = 'CHECK'
SQL)->fetchColumn();
    if ($regras < 6) {
        throw new RuntimeException('Regras (CHECK) de clientes ausentes.');
    }
    echo "Clientes confirmados: tabelas, indices unicos e regras.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao dos clientes: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
