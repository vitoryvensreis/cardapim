<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify') { exit(2); }
require dirname(__DIR__).'/bootstrap/app.php';
$db=\Cardapim\Core\Database::connect();
foreach ([
    'fiscal_perfis'=>['id','id_restaurante','nome','versao'],
    'fiscal_perfil_versoes'=>['id_restaurante','id_perfil','versao','dados','revisado','autor'],
    'fiscal_categoria_perfil'=>['id_restaurante','id_categoria','id_perfil'],
    'fiscal_produtos'=>['id_restaurante','id_produto','id_perfil','versao','dados','autor']
] as $t=>$esperado) {
    $cols=$db->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($esperado as $c) { if (!in_array($c,$cols,true)) { throw new RuntimeException('Schema fiscal incompleto: '.$t); } }
}
echo "Schema de produtos fiscais confirmado.\n";
