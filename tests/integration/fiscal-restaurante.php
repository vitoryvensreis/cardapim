<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/bootstrap/app.php';
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Shared\Seguranca\Cripto;
$db = \Cardapim\Core\Database::connect();
Cripto::usarChave(str_repeat('f',32));
$ids = $db->query('SELECT id FROM restaurantes ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 1) { throw new RuntimeException('Restaurante de teste ausente.'); }
$id = (int) $ids[0];
$r = new FiscalRepository($db);
$db->beginTransaction();
try {
    $r->serializar($id, function () use ($r,$id): void {
        $r->salvar($id,['cnpj'=>'11222333000181','habilitado'=>true,'aprovado'=>false,'id_impressora'=>null,'dados'=>['token'=>'segredo-fiscal-teste']]);
    });
    $row = $r->buscar($id);
    if (!$row['habilitado'] || $row['dados']['token'] !== 'segredo-fiscal-teste') { throw new RuntimeException('Configuração não persistida.'); }
    $q=$db->prepare('SELECT dados FROM restaurante_fiscal WHERE id_restaurante=?'); $q->execute([$id]);
    if (str_contains($q->fetchColumn(),'segredo-fiscal-teste')) { throw new RuntimeException('Credencial exposta.'); }
    if (isset($ids[1]) && $r->buscar((int)$ids[1]) !== null) { throw new RuntimeException('Isolamento fiscal falhou.'); }
    if (str_contains(json_encode($r->resumoPlataforma()),'segredo-fiscal-teste')) { throw new RuntimeException('Credencial exposta no resumo.'); }
} finally { $db->rollBack(); Cripto::usarChave(null); }
echo "Persistência e projeção fiscal confirmadas.\n";
