<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') { exit(2); }
require dirname(__DIR__) . '/bootstrap/app.php';
$db = \Cardapim\Core\Database::connect();
$cols = $db->query('SHOW COLUMNS FROM restaurante_fiscal')->fetchAll(PDO::FETCH_COLUMN);
foreach (['id_restaurante','cnpj','habilitado','aprovado','id_impressora','dados','atualizado_em'] as $c) {
    if (!in_array($c, $cols, true)) { fwrite(STDERR, "Falta coluna fiscal.\n"); exit(1); }
}
$idx = $db->query('SHOW INDEX FROM restaurante_fiscal')->fetchAll(PDO::FETCH_ASSOC);
if (!array_filter($idx, static fn ($i) => $i['Column_name'] === 'cnpj' && (int) $i['Non_unique'] === 0)) { exit(1); }
echo "Schema fiscal por restaurante confirmado.\n";
