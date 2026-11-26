<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify') { exit(2); }
require dirname(__DIR__).'/bootstrap/app.php';
$db=\Cardapim\Core\Database::connect();
$cols=$db->query('SHOW COLUMNS FROM fiscal_liberacoes')->fetchAll(PDO::FETCH_COLUMN);
foreach (['producao','producao_autor','producao_em'] as $c) { if (!in_array($c,$cols,true)) { throw new RuntimeException('Schema fiscal incompleto: fiscal_liberacoes.'.$c); } }
$q=$db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_impressoes_tipo'");
if (!str_contains((string) $q->fetchColumn(), 'nfce')) { throw new RuntimeException('Impressões não aceitam o tipo nfce.'); }
echo "Schema do DANFE e da liberação de produção confirmado.\n";
