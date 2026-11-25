<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/app.php';
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Services\Gerencia\FiscalProdutosService as S;
use Cardapim\Services\Gerencia\FiscalAcesso as A;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Seguranca\Cripto;
$db=\Cardapim\Core\Database::connect();
Cripto::usarChave(str_repeat('f',32));
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='cardapim_ci') { throw new RuntimeException('Este teste exige o banco descartável cardapim_ci.'); }
$rest=$db->query("SELECT r.id,r.cnpj FROM restaurantes r WHERE r.slug='restaurante-modelo-cardapim' AND r.cnpj IS NOT NULL AND EXISTS (SELECT 1 FROM produtos p WHERE p.id_restaurante=r.id AND p.ativo=1) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$rest) { throw new RuntimeException('Restaurante modelo não preparado.'); }
$id=(int)$rest['id'];
$f=new FiscalRepository($db);
$old=$f->buscar($id);
$admin=new UsuarioLogado(1,'adm_master','CI','Fiscal',true,null,null,null,null);
$s=new S($db,$id,$admin);
function fpCheck(bool $v):void { if (!$v) throw new RuntimeException('Falha na integração fiscal de produtos.'); }
$produtos=$db->prepare('SELECT id,nome,preco,codigo,unidade_venda FROM produtos WHERE id_restaurante=? AND ativo=1 ORDER BY id LIMIT 2');
$produtos->execute([$id]); $originais=$produtos->fetchAll(PDO::FETCH_ASSOC);
fpCheck(count($originais)>0);
try {
    fpCheck(!A::permitido($db,$id));
    $gerente=new UsuarioLogado(2,'gerente','CI','Fiscal',true,$id,null,null,null);
    try { A::alterar($db,$gerente,$id,true); throw new LogicException('Gerente liberou fiscal.'); } catch (RuntimeException) {}
    A::alterar($db,$admin,$id,true);
    fpCheck(A::permitido($db,$id));
    $r=$old ?? ['cnpj'=>$rest['cnpj'],'habilitado'=>false,'aprovado'=>false,'id_impressora'=>null,'dados'=>[]];
    $r['habilitado']=true;
    $r['dados']['empresa']=['crt'=>'4','estado'=>'CE'];
    $f->serializar($id,fn()=> $f->salvar($id,$r));
    fpCheck(S::disponivel($db,$id));
    $p=['nome'=>'Perfil CI '.bin2hex(random_bytes(4)),'operacao'=>'producao','cfop'=>'5101','situacao_icms'=>'102','inicio'=>'2026-01-01','referencia'=>'Teste CI','revisado'=>'1'];
    $perfil=$s->perfil($p);
    $ids=array_map(static fn($p)=>(string)$p['id'],$originais);
    $outro=$db->prepare('SELECT p.id FROM produtos p WHERE p.id_restaurante<>? AND p.ativo=1 LIMIT 1');
    $outro->execute([$id]); $estranho=$outro->fetchColumn();
    try { $s->previa([$estranho===false?'999999999999999999':(string)$estranho],$perfil); throw new LogicException('Produto de outro restaurante aceito.'); } catch (RuntimeException) {}
    $prev=$s->previa($ids,$perfil);
    fpCheck(count($prev['itens'])===count($ids));
    fpCheck($s->aplicar($prev)===count($ids));
    $e=$s->estado();
    foreach ($ids as $pid) { fpCheck((int)$e['produtos'][(int)$pid]['id_perfil']===$perfil); }
    $pid=(int)$ids[0];
    $s->produto($pid,['id_perfil'=>(string)$perfil,'ncm'=>'','origem'=>'0','gtin'=>'SEM GTIN','gtin_tributavel'=>'SEM GTIN','unidade_comercial'=>'UN','unidade_tributavel'=>'UN','fator'=>'1']);
    fpCheck($s->estado()['produtos'][$pid]['dados']['origem']==='0');
    $lote=$s->previa([(string)$pid],null,['campos'=>['origem','cest'],'origem'=>'1','cest'=>'1705000']);
    fpCheck($lote['itens'][0]['mudancas']===['cest'=>['de'=>'','para'=>'1705000']]);
    $s->aplicar($lote);
    $dados=$s->estado()['produtos'][$pid]['dados'];
    fpCheck($dados['origem']==='0' && $dados['gtin']==='SEM GTIN' && $dados['cest']==='1705000' && !$dados['confirmado']);
    $lote=$s->previa([(string)$pid],null,['campos'=>['origem'],'origem'=>'1','sobrescrever'=>'1']);
    $s->aplicar($lote);
    fpCheck($s->estado()['produtos'][$pid]['dados']['origem']==='1');
    $import=$s->lerImportacao('csv',"nome;ncm;origem;fator\nProduto teste;;2;2\n");
    $dest=[0=>(string)$pid];
    $pv=$s->previaImportacao($import,$dest,true);
    fpCheck($pv[0]['dados']['origem']==='2' && $pv[0]['dados']['gtin']==='SEM GTIN');
    try { $s->previaImportacao($import,[0=>'999999999999999999'],true); throw new LogicException('Importação fora do tenant aceita.'); } catch (RuntimeException) {}
    fpCheck($s->aplicarImportacao($import,$dest,true,$pv)===1);
    fpCheck($s->estado()['produtos'][$pid]['dados']['fator']==='2');
    try { $s->aplicarImportacao($import,$dest,true,$pv); throw new LogicException('Prévia antiga de importação aceita.'); } catch (RuntimeException) {}
    $oldPreview=$s->previa($ids,$perfil);
    $s->perfil($p+['id_perfil'=>(string)$perfil,'versao'=>'1','confirmar_impacto'=>'1']);
    try { $s->aplicar($oldPreview); throw new LogicException('Prévia antiga aceita.'); } catch (RuntimeException) {}
    fpCheck((int)$s->estado()['produtos'][$pid]['versao']===1);
    $s->aplicar($s->previa($ids,$perfil));
    $cat=$s->estado()['categorias'][0]['id'] ?? null;
    if ($cat!==null) {
        $s->categoria((int)$cat,$perfil);
        $cats=array_column($s->estado()['categorias'],'id_perfil','id');
        fpCheck((int)$cats[$cat]===$perfil);
    }
    fpCheck((int)$s->estado()['produtos'][$pid]['versao']===2);
    $catalogos=new \Cardapim\Modules\Fiscal\CatalogosFiscais($db);
    try { $catalogos->importar($gerente,'cfop','CI-FORBIDDEN','2020-01-01',"codigo;descricao\n5101;Teste\n"); throw new LogicException('Gerente importou catálogo global.'); } catch (RuntimeException) {}
    fpCheck($catalogos->importar($admin,'cfop','CI-FISCAL-TESTE','2020-01-01',"codigo;descricao\n5101;Teste\n")===1);
    fpCheck($catalogos->importar($admin,'cfop','CI-FISCAL-FUTURO','2099-01-01',"codigo;descricao\n5102;Teste futuro\n")===1);
    fpCheck($catalogos->vigente('cfop')['versao']==='CI-FISCAL-TESTE');
    $conferencia=$s->conferencia();
    fpCheck(isset($conferencia['verificacao'][$pid]));
    $db->beginTransaction();
    try {
        $pInsert=$db->prepare('INSERT INTO produtos(id_restaurante,nome,preco,controla_estoque,disponivel) VALUES (?,?,?,0,1)');
        $pInsert->execute([$id,'Combo fiscal CI','15.00']); $comboId=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO combos(id_produto,id_restaurante,maximo) VALUES (?,?,NULL)')->execute([$comboId,$id]);
        $db->prepare('INSERT INTO combo_itens(id_combo,id_produto,id_restaurante,quantidade,ordem) VALUES (?,?,?,?,0)')->execute([$comboId,$pid,$id,1]);
        $comRepo=new \Cardapim\Repositories\Estoque\ComplementoRepository($db);
        $gid=$comRepo->criarGrupo($id,'Extras fiscais CI',0,1,1);
        $oid=$comRepo->criarOpcao($id,$gid,['nome'=>'Extra CI','descricao'=>null,'preco'=>'1.00','codigo'=>null,'imagem_path'=>null,'disponivel'=>1,'ordem'=>0]);
        $comRepo->salvarGruposDoProduto($id,$comboId,[$gid]);
        $com=new \Cardapim\Modules\Fiscal\ComposicaoFiscal($db,$id);
        $estruturas=$com->estruturas();$est=$estruturas[$comboId];
        fpCheck($est['combo'] && $est['fixos'][0]['id_produto']===$pid && isset($est['opcoes'][$oid]));
        $cfg=\Cardapim\Modules\Fiscal\ComposicaoFiscal::normalizar($comboId,$est,[$pid=>['id'=>$pid]],$estruturas,
            ['confirmado'=>'1','assinatura'=>\Cardapim\Modules\Fiscal\ComposicaoFiscal::assinatura($est),'referencia'=>'CI','opcoes'=>[$oid=>['modo'=>'produto','produto'=>(string)$pid]]]);
        $com->salvar($comboId,$est,$cfg,$admin->id);
        fpCheck($com->configuracoes()[$comboId]['dados']['opcoes'][$oid]['produto']===$pid);
    } finally { $db->rollBack(); }
    $r['habilitado']=false; $f->serializar($id,fn()=> $f->salvar($id,$r));
    fpCheck(!S::disponivel($db,$id));
    try { $s->produto($pid,[]); throw new LogicException('Módulo desligado aceitou escrita.'); } catch (RuntimeException) {}
    $r['habilitado']=true; $f->serializar($id,fn()=> $f->salvar($id,$r));
    fpCheck((int)$s->estado()['produtos'][$pid]['versao']===2);
    $antes=$s->estado()['produtos'][$pid];
    A::global($db,$admin,false);
    fpCheck(!S::disponivel($db,$id));
    try { $s->aplicar($oldPreview); throw new LogicException('Global desligado aceitou escrita.'); } catch (RuntimeException) {}
    A::global($db,$admin,true);
    fpCheck(S::disponivel($db,$id));
    A::alterar($db,$admin,$id,false);
    fpCheck(!S::disponivel($db,$id));
    A::alterar($db,$admin,$id,true);
    fpCheck($s->estado()['produtos'][$pid]===$antes);
    $produtos->execute([$id]); fpCheck($produtos->fetchAll(PDO::FETCH_ASSOC)===$originais);
} finally {
    $db->prepare('DELETE FROM fiscal_catalogos WHERE versao IN (?,?)')->execute(['CI-FISCAL-TESTE','CI-FISCAL-FUTURO']);
    A::global($db,$admin,true);
    $db->prepare('DELETE FROM fiscal_liberacoes WHERE id_restaurante=?')->execute([$id]);
    foreach (['fiscal_produtos','fiscal_categoria_perfil','fiscal_perfis'] as $t) { $db->prepare('DELETE FROM '.$t.' WHERE id_restaurante=?')->execute([$id]); }
    if ($old===null) { $db->prepare('DELETE FROM restaurante_fiscal WHERE id_restaurante=?')->execute([$id]); }
    else { $f->serializar($id,fn()=> $f->salvar($id,$old)); }
    Cripto::usarChave(null);
}
echo "Vínculos fiscais, versões, módulo desligado e catálogo comercial preservado.\n";
