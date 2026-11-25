<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/bootstrap/app.php';
use Cardapim\Modules\Fiscal\ConsultaCadastroFiscal;
use Cardapim\Modules\Fiscal\MunicipioIbge;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;
final class ConsultaFiscalFake extends ClienteHttp {
    public array $pedidos = [];
    public function enviar(string $metodo,string $url,array $cabecalhos=[],array|string|null $corpo=null): RespostaHttp {
        $this->pedidos[] = [$metodo,$url];
        return new RespostaHttp(200,json_encode(['cnpj'=>'11222333000181','razao_social'=>'Empresa Teste','nome_fantasia'=>'Teste','cep'=>'60060440','municipio'=>'FORTALEZA','uf'=>'CE','opcao_pelo_mei'=>true,'qsa'=>[['cpf'=>'segredo']],'inscricao_estadual'=>'nao confiavel'],JSON_THROW_ON_ERROR));
    }
}
function cadastroFiscalOk(bool $v): void { if (!$v) throw new RuntimeException('Falha cadastro fiscal.'); }
$fake = new ConsultaFiscalFake();
$d = (new ConsultaCadastroFiscal($fake))->consultar('11222333000181');
cadastroFiscalOk($fake->pedidos === [['GET','https://brasilapi.com.br/api/cnpj/v1/11222333000181']]);
cadastroFiscalOk($d['crt'] === '4' && $d['cep'] === '60060-440' && !isset($d['qsa'],$d['ie'],$d['ibge']));
cadastroFiscalOk(!str_contains(json_encode($d),'segredo'));
cadastroFiscalOk(!isset(ConsultaCadastroFiscal::normalizar('11222333000181',['cnpj'=>'11222333000181','razao_social'=>'Teste','opcao_pelo_mei'=>false,'opcao_pelo_simples'=>false])['crt']));
try { ConsultaCadastroFiscal::normalizar('11222333000181',['cnpj'=>'11444777000161','razao_social'=>'Outro']); throw new LogicException('CNPJ trocado aceito.'); } catch (RuntimeException) {}
$lista=[['id'=>2304400,'nome'=>'Fortaleza'],['id'=>2303709,'nome'=>'Caucaia'],['id'=>2312908,'nome'=>'Sobral']];
cadastroFiscalOk(MunicipioIbge::identificar($lista,'FORTALEZA','CE') === '2304400');
cadastroFiscalOk(MunicipioIbge::identificar($lista,'Fortaleza','RN') === null);
cadastroFiscalOk(MunicipioIbge::identificar($lista,'Outro','CE') === null);
$pronto=['ie_situacao'=>'possui','ie'=>'123456789','ibge'=>'2304400','ibge_verificado'=>true];
cadastroFiscalOk(\Cardapim\Services\Gerencia\FiscalService::empresaPronta($pronto));
cadastroFiscalOk(!\Cardapim\Services\Gerencia\FiscalService::empresaPronta(array_replace($pronto,['ie_situacao'=>'isento','ie'=>''])));
cadastroFiscalOk(!\Cardapim\Services\Gerencia\FiscalService::empresaPronta(array_replace($pronto,['ibge_verificado'=>false])));
echo "Consulta fiscal, projeção e município aprovados.\n";
