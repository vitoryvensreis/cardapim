<?php

declare(strict_types=1);

// Conferencia read-only das opcoes do cupom: margem lateral por impressora, nome de quem atendeu e total no delivery (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-opcoes-cupom.php --verify\n");
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
    $m = $col('impressoras', 'margem_lateral_mm');
    if ($m === null || $m['COLUMN_TYPE'] !== 'decimal(3,1)' || $m['IS_NULLABLE'] !== 'YES' || $m['COLUMN_DEFAULT'] !== null) {
        throw new RuntimeException('Falta impressoras.margem_lateral_mm (DECIMAL(3,1) NULL DEFAULT NULL).');
    }
    foreach (['mostrar_atendente' => '1', 'delivery_com_preco' => '0'] as $c => $padrao) {
        $r = $col('restaurante_impressao_config', $c);
        if ($r === null || $r['COLUMN_TYPE'] !== 'tinyint(1)' || $r['IS_NULLABLE'] !== 'NO' || (string) $r['COLUMN_DEFAULT'] !== $padrao) {
            throw new RuntimeException("Falta restaurante_impressao_config.$c (TINYINT(1) NOT NULL DEFAULT $padrao).");
        }
    }
    echo "Opcoes do cupom confirmadas.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Opcoes do cupom: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
