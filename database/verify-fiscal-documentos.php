<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argv[1] ?? '')!=='--verify') { exit(2); }
require dirname(__DIR__).'/bootstrap/app.php';
$db=\Cardapim\Core\Database::connect();
$tabelas=[
    'fiscal_numeracao'=>['id_restaurante','ambiente','serie','proximo'],
    'fiscal_documentos'=>['id','id_restaurante','id_pedido','origem','ambiente','serie','numero','codigo_nf','chave','status','cod_status','motivo','protocolo','cpf_cnpj','valor','dados','qrcode','url_consulta','xml_arquivo','tentativas','proxima_tentativa_em','autorizada_em','cancelada_em','impressa_em'],
    'fiscal_eventos'=>['id','id_restaurante','id_documento','tipo','ambiente','serie','numero_inicial','numero_final','justificativa','cod_status','motivo','protocolo','autor'],
];
foreach ($tabelas as $t=>$esperado) {
    $cols=$db->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($esperado as $c) { if (!in_array($c,$cols,true)) { throw new RuntimeException('Schema fiscal incompleto: '.$t.'.'.$c); } }
}
echo "Schema de emissão fiscal confirmado.\n";
