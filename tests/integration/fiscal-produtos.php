<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/app.php';
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Services\Gerencia\FiscalProdutosService as S;
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
    $r['habilitado']=false; $f->serializar($id,fn()=> $f->salvar($id,$r));
    fpCheck(!S::disponivel($db,$id));
    try { $s->produto($pid,[]); throw new LogicException('Módulo desligado aceitou escrita.'); } catch (RuntimeException) {}
    $r['habilitado']=true; $f->serializar($id,fn()=> $f->salvar($id,$r));
    fpCheck((int)$s->estado()['produtos'][$pid]['versao']===2);
    $produtos->execute([$id]); fpCheck($produtos->fetchAll(PDO::FETCH_ASSOC)===$originais);
} finally {
    foreach (['fiscal_produtos','fiscal_categoria_perfil','fiscal_perfis'] as $t) { $db->prepare('DELETE FROM '.$t.' WHERE id_restaurante=?')->execute([$id]); }
    if ($old===null) { $db->prepare('DELETE FROM restaurante_fiscal WHERE id_restaurante=?')->execute([$id]); }
    else { $f->serializar($id,fn()=> $f->salvar($id,$old)); }
    Cripto::usarChave(null);
}
echo "Vínculos fiscais, versões, módulo desligado e catálogo comercial preservado.\n";
