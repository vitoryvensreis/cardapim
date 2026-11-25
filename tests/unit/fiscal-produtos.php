<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/app.php';
use Cardapim\Modules\Fiscal\CadastroProdutoFiscal as V;
use Cardapim\Modules\Fiscal\CatalogoNcm;
function fpOk(bool $v): void { if (!$v) { throw new RuntimeException('Falha na validação fiscal de produtos.'); } }
function fpRejeitar(callable $f): void { try { $f(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Entrada inválida aceita.'); }
$empresa=['crt'=>'4','estado'=>'CE'];
$p=['nome'=>'Perfil teste','operacao'=>'producao','cfop'=>'5102','situacao_icms'=>'102','inicio'=>'2026-01-01','referencia'=>'Orientação de teste','revisado'=>'1','aliq_icms'=>'0,1234'];
$r=V::perfil($p,$empresa);
fpOk($r['revisado'] && $r['dados']['aliq_icms']==='0.1234');
$r['versao']=1;
fpOk(V::perfilVigente($r,$empresa));
fpOk(!V::perfilVigente($r,['crt'=>'1','estado'=>'CE']));
fpRejeitar(fn()=>V::perfil(array_replace($p,['situacao_icms'=>'100']),$empresa));
foreach (['5101','5103','5104','5115','5405'] as $cfop) { fpRejeitar(fn()=>V::perfil(array_replace($p,['cfop'=>$cfop]),$empresa)); }
foreach (['500','900'] as $csosn) { fpRejeitar(fn()=>V::perfil(array_replace($p,['situacao_icms'=>$csosn]),$empresa)); }
fpOk(V::perfil(array_replace($p,['situacao_icms'=>'300']),$empresa)['revisado']);
fpOk(!V::perfilVigente(array_replace($r,['dados'=>array_replace($r['dados'],['cfop'=>'5101'])]),$empresa));
fpOk(\Cardapim\Modules\Fiscal\VerificacaoFiscal::perfil($r['dados'],[])===[]);
fpOk(\Cardapim\Modules\Fiscal\VerificacaoFiscal::perfil(array_replace($r['dados'],['cfop'=>'5101']),[])!==[]);
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
$d['ncm_fonte']='BrasilAPI | 22021000 | '.date('Y-m-d').' | 2022-04-01 a 9999-12-31';
fpOk(V::pendencias(['dados'=>$d,'versao'=>1],$r,$empresa)===[]);
fpOk(V::pendencias(['dados'=>$d,'versao'=>2],$r,$empresa)!==[]);
$n=['itens'=>['22021000'=>'Águas aromatizadas'],'versao'=>$d['ncm_fonte']];
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;
final class NcmHttpFake extends ClienteHttp {
    public array $urls=[];
    public function __construct(private RespostaHttp $resp) {}
    public function enviar(string $metodo,string $url,array $cabecalhos=[],array|string|null $corpo=null): RespostaHttp {
        $this->urls[]=$url; return $this->resp;
    }
}
$item=['codigo'=>'2202.10.00','descricao'=>'Águas aromatizadas','data_inicio'=>'2022-04-01','data_fim'=>'9999-12-31'];
$fake=new NcmHttpFake(new RespostaHttp(200,json_encode($item,JSON_THROW_ON_ERROR)));
$res=(new CatalogoNcm($fake))->consultar('2202.10.00');
fpOk($fake->urls===[CatalogoNcm::URL.'/22021000'] && $res[0]['codigo']==='22021000' && CatalogoNcm::fonteValida('22021000',$res[0]['fonte']));
fpOk(!CatalogoNcm::fonteValida('22021001',$res[0]['fonte']));
fpOk(!CatalogoNcm::fonteValida('22021000','BrasilAPI | 22021000 | 2000-01-01 | 1990-01-01 a 9999-12-31'));
$fake=new NcmHttpFake(new RespostaHttp(200,json_encode([$item,array_replace($item,['codigo'=>'22']),array_replace($item,['codigo'=>'0101.21.00']),array_replace($item,['codigo'=>'2202.90.00','data_fim'=>'2022-04-02'])],JSON_THROW_ON_ERROR)));
$res=(new CatalogoNcm($fake))->consultar('Águas');
fpOk($fake->urls===[CatalogoNcm::URL.'?search='.rawurlencode('Águas')] && count($res)===2 && $res[1]['codigo']==='01012100');
$fake=new NcmHttpFake(new RespostaHttp(404,'{}'));
fpOk((new CatalogoNcm($fake))->consultar('99999999')===[]);
foreach ([new RespostaHttp(503,'{}'),new RespostaHttp(200,'{}'),new RespostaHttp(200,'not-json')] as $resp) {
    try { (new CatalogoNcm(new NcmHttpFake($resp)))->consultar('2202'); throw new LogicException('Falha de API aceitou consulta.'); } catch (RuntimeException|JsonException) {}
}
$fake=new NcmHttpFake(new RespostaHttp(200,'[]'));
fpOk((new CatalogoNcm($fake))->consultar('2202')===[] && $fake->urls===[CatalogoNcm::URL.'?search=2202']);
$many=[];
for ($i=0;$i<40;$i++) { $many[]=array_replace($item,['codigo'=>sprintf('2202%04d',$i)]); }
fpOk(count(CatalogoNcm::normalizar($many))===35);
fpOk(CatalogoNcm::normalizar([array_replace($item,['data_inicio'=>'2099-01-01'])])===[]);
// Atalhos comerciais não são uma tabela de tributação nem alteram consulta exata.
fpOk(array_keys(CatalogoNcm::termosComerciais('PIZZA calabresa'))===[19059090]);
fpOk(array_keys(CatalogoNcm::termosComerciais('Refrigerante'))===[22021000,22029900]);
fpOk(array_keys(CatalogoNcm::termosComerciais('Hambúrguer artesanal'))===[16025000,19059090]);
fpOk(CatalogoNcm::termosComerciais('X-Burger')!==[] && CatalogoNcm::termosComerciais('Refri')!==[]);
foreach (['22021000','fluido refrigerante','massa de pizza','pão de hambúrguer','combo pizza refrigerante','refrigerador','Águas'] as $literal) { fpOk(CatalogoNcm::termosComerciais($literal)===[]); }
final class NcmAssistidoFake extends ClienteHttp {
    public array $urls=[];
    public function __construct(private array $respostas) {}
    public function enviar(string $metodo,string $url,array $cabecalhos=[],array|string|null $corpo=null): RespostaHttp {
        $this->urls[]=$url;
        if (!isset($this->respostas[$url])) { throw new LogicException('Consulta inesperada '.$url); }
        return $this->respostas[$url];
    }
}
$baseNcm=CatalogoNcm::URL;
$respNcm=static fn(string $codigo,string $descricao)=>new RespostaHttp(200,json_encode(array_replace($item,['codigo'=>$codigo,'descricao'=>$descricao]),JSON_THROW_ON_ERROR));
$fakeA=new NcmAssistidoFake([$baseNcm.'/22021000'=>$respNcm('22021000','Águas aromatizadas'),$baseNcm.'/22029900'=>$respNcm('22029900','Outras bebidas')]);
$extras=0;
$a=(new CatalogoNcm($fakeA))->consultarAssistido('Refrigerante',static function() use (&$extras){$extras++;return true;});
fpOk($extras===1 && $a['assistida'] && !$a['parcial'] && count($a['resultados'])===2 && $a['resultados'][0]['codigo']==='22021000' && $a['resultados'][0]['descricao']==='Águas aromatizadas' && isset($a['resultados'][0]['sugestao_comercial']));
fpOk($fakeA->urls===[$baseNcm.'/22021000',$baseNcm.'/22029900']);
$fakeA=new NcmAssistidoFake([$baseNcm.'/22021000'=>$respNcm('22021000','Águas aromatizadas')]);
$a=(new CatalogoNcm($fakeA))->consultarAssistido('Refri',static fn()=>false);
fpOk($a['parcial'] && count($a['resultados'])===1 && count($fakeA->urls)===1);
$fakeA=new NcmAssistidoFake([$baseNcm.'/19059090'=>new RespostaHttp(404,'{}')]);
$a=(new CatalogoNcm($fakeA))->consultarAssistido('Pizza',static fn()=>true);
fpOk($a['assistida'] && $a['resultados']===[]);
$fakeA=new NcmAssistidoFake([$baseNcm.'/16025000'=>$respNcm('16025000','Preparações bovinas'),$baseNcm.'/19059090'=>new RespostaHttp(503,'{}')]);
$a=(new CatalogoNcm($fakeA))->consultarAssistido('Hambúrguer',static fn()=>true);
fpOk($a['parcial'] && count($a['resultados'])===1);
$fakeA=new NcmAssistidoFake([$baseNcm.'/19059090'=>$respNcm('19059090','Outros produtos de padaria')]);
$a=(new CatalogoNcm($fakeA))->consultarAssistido('19059090',static fn()=>false);
fpOk(!$a['assistida'] && !$a['parcial'] && !isset($a['resultados'][0]['sugestao_comercial']));
echo "BrasilAPI NCM: consulta exata e parcial, vigência, limite, ausência e falhas simuladas aprovadas.\n";
echo "Validação cadastral fiscal, GTIN, perfil, vigência e catálogo NCM confirmados.\n";

use Cardapim\Services\Gerencia\FiscalProdutosService as Lote;
fpOk(Lote::loteCampos(['campos'=>['origem','fator'],'origem'=>'0','fator'=>'1,25'])===['origem'=>'0','fator'=>'1.25']);
try { Lote::loteCampos(['campos'=>['gtin'],'gtin'=>'SEM GTIN']); throw new LogicException('GTIN em massa aceito.'); } catch (RuntimeException) {}
$m=Lote::mesclarLote(['origem'=>'0','gtin'=>'SEM GTIN','confirmado'=>true],['origem'=>'1','ncm'=>'22021000'],false,$n);
fpOk($m['dados']['origem']==='0' && $m['dados']['gtin']==='SEM GTIN' && $m['dados']['ncm_fonte']===$n['versao'] && !$m['dados']['confirmado']);
fpOk(Lote::mesclarLote(['origem'=>'0'],['origem'=>'1'],true,null)['dados']['origem']==='1');
try { Lote::mesclarLote([],['ncm'=>'99999999'],false,$n); throw new LogicException('NCM ausente aceito.'); } catch (RuntimeException) {}
