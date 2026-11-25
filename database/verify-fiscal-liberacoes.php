<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify') { exit(2); }
require dirname(__DIR__).'/bootstrap/app.php';
$db=\Cardapim\Core\Database::connect();
foreach (['fiscal_visibilidade'=>['id','visivel','autor','atualizado_em'],'fiscal_liberacoes'=>['id_restaurante','permitido','autor','atualizado_em']] as $t=>$esperado) {
    $cols=$db->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($esperado as $c) { if (!in_array($c,$cols,true)) { throw new RuntimeException('Schema fiscal incompleto: '.$t); } }
}
if ((int)$db->query('SELECT COUNT(*) FROM fiscal_visibilidade WHERE id=1')->fetchColumn()!==1) { throw new RuntimeException('Controle global fiscal ausente.'); }
echo "Schema de liberação fiscal confirmado.\n";
