<?php

declare(strict_types=1);

// Somente leitura: confirma cupons, fidelidade e as colunas de desconto em pedidos.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-cupons.php --verify\n");
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
    $exigir = static function (string $tabela, array $esperado) use ($colunas): void {
        $c = $colunas($tabela);
        foreach ($esperado as $nome => $tipo) {
            if (($c[$nome] ?? '') !== $tipo) {
                throw new RuntimeException('Coluna ' . $tabela . '.' . $nome . ' incorreta ou ausente.');
            }
        }
    };
    $exigir('cupons', ['id_restaurante' => 'bigint unsigned', 'codigo' => 'varchar(20)', 'tipo' => "enum('percentual','fixo')", 'valor' => 'decimal(10,2)',
        'pedido_minimo' => 'decimal(10,2)', 'limite_usos' => 'int unsigned', 'um_por_cliente' => 'tinyint(1)', 'valido_ate' => 'date', 'ativo' => 'tinyint(1)']);
    $exigir('restaurante_fidelidade', ['id_restaurante' => 'bigint unsigned', 'ativo' => 'tinyint(1)', 'compras_necessarias' => 'smallint unsigned',
        'tipo' => "enum('percentual','fixo')", 'valor' => 'decimal(10,2)', 'valor_minimo' => 'decimal(10,2)']);
    $exigir('pedidos', ['desconto_origem' => "enum('cupom','fidelidade')", 'id_cupom' => 'bigint unsigned', 'cupom_codigo' => 'varchar(20)']);
    $fk = $db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_pedidos_cupom'")->fetchColumn();
    if ((int) $fk !== 1) {
        throw new RuntimeException('Ligacao pedidos -> cupons (fk_pedidos_cupom) ausente.');
    }
    echo "Cupons e fidelidade confirmados: cupons, restaurante_fidelidade e desconto de origem nos pedidos.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao de cupons e fidelidade: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
