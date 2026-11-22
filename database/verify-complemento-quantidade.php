<?php

declare(strict_types=1);

// Conferencia read-only: quantidade por opcao de complemento e liga/desliga do aviso de cancelamento (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-complemento-quantidade.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $col = static function (string $tabela, string $coluna) use ($db): ?array {
        $q = $db->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $q->execute([$tabela, $coluna]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    };
    $r = $col('complemento_grupos', 'repetir');
    if ($r === null || $r['COLUMN_TYPE'] !== 'tinyint(1)' || (string) $r['COLUMN_DEFAULT'] !== '0') {
        throw new RuntimeException('Falta complemento_grupos.repetir (padrao 0).');
    }
    $r = $col('complementos', 'qtd_max');
    if ($r === null || $r['IS_NULLABLE'] !== 'YES') {
        throw new RuntimeException('Falta complementos.qtd_max (NULL = livre).');
    }
    $r = $col('restaurante_impressao_config', 'cancelamento');
    if ($r === null || $r['COLUMN_TYPE'] !== 'tinyint(1)' || (string) $r['COLUMN_DEFAULT'] !== '1') {
        throw new RuntimeException('Falta restaurante_impressao_config.cancelamento (padrao 1).');
    }
    echo "Complementos com quantidade e aviso de cancelamento configuravel confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Complementos/impressao: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
