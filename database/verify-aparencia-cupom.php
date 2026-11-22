<?php

declare(strict_types=1);

// Conferencia read-only da aparencia do cupom e do papel informado das impressoras (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-aparencia-cupom.php --verify\n");
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
    $p = $col('impressoras', 'papel_informado');
    if ($p === null || $p['COLUMN_TYPE'] !== 'tinyint(1)' || $p['IS_NULLABLE'] !== 'NO' || (string) $p['COLUMN_DEFAULT'] !== '0') {
        throw new RuntimeException('Falta impressoras.papel_informado (TINYINT(1) NOT NULL DEFAULT 0).');
    }
    foreach (['fonte' => 'varchar(20)', 'peso_texto' => 'smallint unsigned', 'peso_destaque' => 'smallint unsigned', 'tamanho' => 'varchar(10)'] as $c => $tipo) {
        $r = $col('restaurante_cupom_aparencia', $c);
        if ($r === null || $r['COLUMN_TYPE'] !== $tipo || $r['IS_NULLABLE'] !== 'NO') {
            throw new RuntimeException("Falta restaurante_cupom_aparencia.$c ($tipo NOT NULL).");
        }
    }
    echo "Aparencia do cupom confirmada.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Aparencia do cupom: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
