<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/app.php';
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as V;
use Cardapim\Modules\Fiscal\CatalogoNcm;
function fpOk(bool $v): void { if (!$v) { throw new RuntimeException('Falha na validação fiscal de produtos.'); } }
function fpRejeitar(callable $f): void { try { $f(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Entrada inválida aceita.'); }
$empresa=['crt'=>'4','estado'=>'CE'];
$p=['nome'=>'Perfil teste','operacao'=>'producao','cfop'=>'5101','situacao_icms'=>'102','inicio'=>'2026-01-01','referencia'=>'Orientação de teste','revisado'=>'1','aliq_icms'=>'0,1234'];
$r=V::perfil($p,$empresa);
fpOk($r['revisado'] && $r['dados']['aliq_icms']==='0.1234');
$r['versao']=1;
fpOk(V::perfilVigente($r,$empresa));
fpOk(!V::perfilVigente($r,['crt'=>'1','estado'=>'CE']));
fpRejeitar(fn()=>V::perfil(array_replace($p,['situacao_icms'=>'100']),$empresa));
fpRejeitar(fn()=>V::perfil(array_replace($p,['inicio'=>'2026-02-30']),$empresa));
fpRejeitar(fn()=>V::perfil(array_replace($p,['referencia'=>'']),$empresa));
fpRejeitar(fn()=>V::perfil(array_replace($p,['aliq_icms'=>'101']),$empresa));
fpOk(V::gtin('7894900011517') && !V::gtin('7894900011518'));
$d=V::produto(['ncm'=>'22021000','cest'=>'','origem'=>'0','gtin'=>'SEM GTIN','gtin_tributavel'=>'SEM GTIN','unidade_comercial'=>'kg','unidade_tributavel'=>'G','fator'=>'1000','confirmado'=>'1']);
fpOk($d['unidade_comercial']==='KG' && $d['fator']==='1000' && $d['origem']==='0');
fpRejeitar(fn()=>V::produto(['ncm'=>'123']));
fpRejeitar(fn()=>V::produto(['gtin'=>'12345678']));
fpRejeitar(fn()=>V::produto(['fator'=>'0']));
fpOk(V::pendencias(['dados'=>$d,'versao'=>1],$r,$empresa)!==[]);
$d['ncm_fonte']='Tabela teste';
fpOk(V::pendencias(['dados'=>$d,'versao'=>1],$r,$empresa)===[]);
fpOk(V::pendencias(['dados'=>$d,'versao'=>2],$r,$empresa)!==[]);
$n=CatalogoNcm::normalizar(['Data_Ultima_Atualizacao_NCM'=>'Vigente em 01/01/2026','Nomenclaturas'=>[['Codigo'=>'22','Descricao'=>'Bebidas'],['Codigo'=>'2202.10.00','Descricao'=>'Águas aromatizadas'],['Codigo'=>'01','Descricao'=>'Animais vivos'],['Codigo'=>'0101.21.00','Descricao'=>'Reprodutores']]]]);
fpOk(count($n['itens'])===2 && CatalogoNcm::pesquisar($n,'BEBIDAS')[0]['codigo']==='22021000' && CatalogoNcm::pesquisar($n,'0101')[0]['codigo']==='01012100');
echo "Validação cadastral fiscal, GTIN, perfil, vigência e catálogo NCM confirmados.\n";
