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
$plano=(int)$db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
relCheck($plano>0);
$nome='Relatorio CI '.bin2hex(random_bytes(5));
$dia='2080-03-15'; $hora=$dia.' 19:30:00';
$db->beginTransaction();
try {
    $ids=[]; $usuarios=[]; $pedidos=[];
    $sufixo=bin2hex(random_bytes(5));
    for($j=0;$j<2;$j++){
        $slug='ci-relatorios-'.$sufixo.'-'.$j;
        $db->prepare("INSERT INTO restaurantes (nome,slug,plano_id,status_rest) VALUES (?,?,?,'ativo')")->execute([$slug,$slug,$plano]);
        $ids[]=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO '.chr(96).'user'.chr(96).' (id_restaurante,cargo,usuario,email,senha_hash,nome,sobrenome,ativo) VALUES (?,?,?,?,?,?,?,1)')
            ->execute([$ids[$j],'gerente',$slug,$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Relatório','CI']);
        $usuarios[]=(int)$db->lastInsertId();
    }
    foreach($ids as $j=>$id) {
        $q=$db->prepare('SELECT COALESCE(MAX(numero),0)+1 FROM pedidos WHERE id_restaurante=?');
        $q->execute([$id]);$numero=(int)$q->fetchColumn();
        $q=$db->prepare("INSERT INTO pedidos (id_restaurante,numero,id_user,origem,tipo,status,pagamento_status,subtotal,taxa_entrega,servico_percentual,taxa_servico,desconto,total,cliente_nome,chave_idempotencia,criado_em,atualizado_em)
            VALUES (?,?,?,'balcao','balcao','aberto','pendente','20.00','0.00','0.00','0.00','0.00','20.00','CI',?,?,?)");
        $q->execute([$id,$numero,$usuarios[$j],bin2hex(random_bytes(16)),$hora,$hora]);$pedido=(int)$db->lastInsertId();$pedidos[]=$pedido;
        $q=$db->prepare("INSERT INTO pedido_itens(id_pedido,id_restaurante,id_user,nome,preco_unitario,quantidade,unidade,total,criado_em)
            VALUES (?,?,?,?,'10.00','2.000','un','20.00',?)");
        $q->execute([$pedido,$id,$usuarios[$j],$nome,$hora]);
        if($j===0) {
            $q->execute([$pedido,$id,$usuarios[$j],$nome,$hora]);
            $item=(int)$db->lastInsertId();
            $db->prepare("UPDATE pedido_itens SET cancelado_em=?,id_user_cancelou=?,motivo_cancelamento='CI' WHERE id=? AND id_restaurante=?")->execute([$hora,$usuarios[$j],$item,$id]);
        }
    }
    $catalogo=[];
    foreach($ids as $j=>$id) {
        $db->prepare('INSERT INTO produtos(id_restaurante,nome,preco,controla_estoque,disponivel) VALUES (?,?,?,0,1)')
            ->execute([$id,$nome.' catálogo '.$j,'10.00']);
        $catalogo[]=(int)$db->lastInsertId();
    }
    $svc=new RelatoriosService($db,(int)$ids[0]);
    $sugestoes=$svc->sugestoes($nome);
    relCheck(count($sugestoes)===1 && $sugestoes[0]['id']===(string)$catalogo[0]);
    relCheck($svc->sugestoes('a')===[] && $svc->sugestoes('%')===[]);
    relCheck(count((new RelatoriosService($db,(int)$ids[1]))->sugestoes($nome))===1);
    $db->prepare('UPDATE pedido_itens SET id_produto=? WHERE id_pedido=? AND id_restaurante=?')
        ->execute([$catalogo[0],$pedidos[0],$ids[0]]);
    $exato=F::daRequisicao(['periodo'=>'personalizado','de'=>$dia,'ate'=>$dia,'produto'=>(string)$catalogo[0],'busca'=>'Nome atual diferente']);
    relCheck(count((new RelatoriosRepository($db,(int)$ids[0]))->linhas($exato)['linhas'])===2);
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
    $fechamento='2080-03-16 00:30:00';
    $db->prepare("UPDATE pedidos SET status='concluido',pagamento_status='pago',forma_pagamento='dinheiro',pago_em=?,concluido_em=?,atualizado_em=? WHERE id=? AND id_restaurante=?")
        ->execute([$fechamento,$fechamento,$fechamento,$pedidos[0],$ids[0]]);
    $ff=F::daRequisicao($f->periodo->parametros()+['busca'=>$nome,'situacao'=>'vendido']);
    $l=$r->linhas($ff)['linhas'];relCheck(count($l)===1&&(float)$l[0]['quantidade']===2.0&&(float)$l[0]['total']===20.0);
    $ff=F::daRequisicao(['periodo'=>'personalizado','de'=>'2080-03-16','ate'=>'2080-03-16','busca'=>$nome,'data_por'=>'fechamento','visao'=>'hora']);
    $l=$r->linhas($ff)['linhas'];relCheck(count($l)===1&&$l[0]['rotulo']==='00:00'&&$l[0]['situacao']==='vendido');
    // Plano de consulta básico para registrar o acesso por restaurante e intervalo sem criar índice prematuramente.
    $q=$db->prepare('EXPLAIN SELECT i.id FROM pedido_itens i JOIN pedidos p ON p.id=i.id_pedido AND p.id_restaurante=i.id_restaurante WHERE i.id_restaurante=? AND i.criado_em>=? AND i.criado_em<?');
    $q->execute([$ids[0],$dia.' 00:00:00','2080-03-16 00:00:00']);
    relCheck(count($q->fetchAll(PDO::FETCH_ASSOC))>0);
} finally { $db->rollBack(); }
echo "Relatórios: quantidades, cancelamento, filtros, CSV e isolamento conferidos.\n";
