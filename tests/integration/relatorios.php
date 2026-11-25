<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/app.php';
use Cardapim\Core\Database;
use Cardapim\Repositories\Gerencia\RelatoriosRepository;
use Cardapim\Services\Gerencia\FiltrosRelatorios as F;
use Cardapim\Services\Gerencia\RelatoriosService;
$db=Database::connect();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='cardapim_ci') throw new RuntimeException('Banco de CI obrigatório.');
function relCheck(bool $v):void { if(!$v) throw new RuntimeException('Falha na integração dos relatórios.'); }
$ids=$db->query('SELECT id FROM restaurantes ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
relCheck(count($ids)===2);
$nome='Relatorio CI '.bin2hex(random_bytes(5));
$dia='2080-03-15'; $hora=$dia.' 19:30:00';
$db->beginTransaction();
try {
    foreach($ids as $j=>$id) {
        $q=$db->prepare('SELECT COALESCE(MAX(numero),0)+1 FROM pedidos WHERE id_restaurante=?');
        $q->execute([$id]);$numero=(int)$q->fetchColumn();
        $q=$db->prepare("INSERT INTO pedidos (id_restaurante,numero,origem,tipo,status,pagamento_status,subtotal,taxa_entrega,servico_percentual,taxa_servico,desconto,total,comanda_nome,chave_idempotencia,criado_em,atualizado_em)
            VALUES (?,?,'balcao','balcao','aberto','pendente','20.00','0.00','0.00','0.00','0.00','20.00','CI',?,?,?)");
        $q->execute([$id,$numero,bin2hex(random_bytes(16)),$hora,$hora]);$pedido=(int)$db->lastInsertId();
        $q=$db->prepare("INSERT INTO pedido_itens(id_pedido,id_restaurante,nome,preco_unitario,quantidade,unidade,total,criado_em)
            VALUES (?,?,?,'10.00','2.000','un','20.00',?)");
        $q->execute([$pedido,$id,$nome,$hora]);
        if($j===0) {
            $q->execute([$pedido,$id,$nome,$hora]);
            $item=(int)$db->lastInsertId();
            $db->prepare("UPDATE pedido_itens SET cancelado_em=?,motivo_cancelamento='CI' WHERE id=? AND id_restaurante=?")->execute([$hora,$item,$id]);
        }
    }
    $f=F::daRequisicao(['periodo'=>'personalizado','de'=>$dia,'ate'=>$dia,'busca'=>$nome]);
    $r=new RelatoriosRepository($db,(int)$ids[0]);
    $linhas=$r->linhas($f)['linhas'];
    relCheck(count($linhas)===2);
    foreach($linhas as $l) { relCheck((float)$l['quantidade']===2.0&&(float)$l['total']===20.0&&(int)$l['pedidos']===1); }
    $ff=F::daRequisicao($f->periodo->parametros()+['busca'=>$nome,'situacao'=>'aberto','visao'=>'hora','hora_de'=>'19:00','hora_ate'=>'19:59']);
    $l=$r->linhas($ff)['linhas'];relCheck(count($l)===1&&$l[0]['rotulo']==='19:00');
    $ff=F::daRequisicao($f->periodo->parametros()+['busca'=>$nome,'situacao'=>'vendido']);relCheck($r->linhas($ff)['linhas']===[]);
    $ff=F::daRequisicao($f->periodo->parametros()+['busca'=>$nome,'visao'=>'pedido']);relCheck(count($r->linhas($ff)['linhas'])===2);
    relCheck(count((new RelatoriosRepository($db,(int)$ids[1]))->linhas($f)['linhas'])===1);
    relCheck(str_contains((new RelatoriosService($db,(int)$ids[0]))->csv($f),$nome));
    // Plano de consulta básico para registrar o acesso por restaurante e intervalo sem criar índice prematuramente.
    $q=$db->prepare('EXPLAIN SELECT i.id FROM pedido_itens i JOIN pedidos p ON p.id=i.id_pedido AND p.id_restaurante=i.id_restaurante WHERE i.id_restaurante=? AND i.criado_em>=? AND i.criado_em<?');
    $q->execute([$ids[0],$dia.' 00:00:00','2080-03-16 00:00:00']);
    relCheck(count($q->fetchAll(PDO::FETCH_ASSOC))>0);
} finally { $db->rollBack(); }
echo "Relatórios: quantidades, cancelamento, filtros, CSV e isolamento conferidos.\n";
