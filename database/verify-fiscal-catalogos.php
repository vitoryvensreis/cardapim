<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify') { exit(2); }
require dirname(__DIR__).'/bootstrap/app.php';
$db=\Cardapim\Core\Database::connect();
foreach (['fiscal_catalogos'=>['id','tipo','versao','vigencia','dados','sha256','autor','criado_em'],'fiscal_composicoes'=>['id_restaurante','id_produto','assinatura','dados','autor','atualizado_em']] as $t=>$esperado) {
    $cols=$db->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($esperado as $c) { if (!in_array($c,$cols,true)) { throw new RuntimeException('Schema fiscal incompleto: '.$t); } }
}
echo "Schema de catálogos e composições fiscais confirmado.\n";
