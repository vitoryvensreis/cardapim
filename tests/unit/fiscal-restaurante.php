<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/bootstrap/app.php';
use Cardapim\Modules\Fiscal\CertificadoA1;
use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;
final class FiscalHttpFalso extends ClienteHttp {
    public array $pedidos = [];
    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp {
        $this->pedidos[] = [$url,$cabecalhos,$corpo];
        return new RespostaHttp(200, '{"sucesso":true,"dados":{"TokenCliente":"token-teste","CodStatus":107}}');
    }
}
function fiscalOk(bool $v): void { if (!$v) { throw new RuntimeException('Teste fiscal falhou.'); } }
$h = new FiscalHttpFalso();
$a = new NextFiscalApi($h);
fiscalOk($a->incluirCliente('11222333000181','admin','11444777000161','Teste') === 'token-teste');
fiscalOk($h->pedidos[0][1]['X-CnpjCliente'] === '11444777000161' && !isset($h->pedidos[0][1]['X-Ambiente']));
$a->certificadoUpload('11222333000181','11444777000161','individual','pfx','senha','Teste','CE','csc','1');
fiscalOk($h->pedidos[1][1]['X-Ambiente'] === '2' && $h->pedidos[1][2]['DocBin'] === base64_encode('pfx'));
$a->configHomologacao('11222333000181','11444777000161','individual','Teste','CE','csc','1');
fiscalOk($h->pedidos[2][2]['FormaEmissao'] === 1 && $h->pedidos[2][1]['X-Ambiente'] === '2');
fiscalOk($a->statusCliente('11222333000181','11444777000161','individual',3) === 107);
try { $a->statusCliente('11222333000181','11444777000161','individual',1); throw new LogicException('Produção não bloqueada'); } catch (RuntimeException) {}
try { CertificadoA1::validar('invalido','senha','11444777000161'); throw new LogicException('Certificado inválido aceito'); } catch (RuntimeException) {}
fiscalOk(CertificadoA1::alerta(time()+6*86400) === 'Vence em até 7 dias');
fiscalOk(CertificadoA1::alerta(time()+14*86400) === 'Vence em até 15 dias');
fiscalOk(CertificadoA1::alerta(time()+29*86400) === 'Vence em até 30 dias');
echo "Testes fiscais sem rede aprovados.\n";

$sim = \Cardapim\Services\Gerencia\FiscalService::configSimulacao([]);
fiscalOk($sim === ['serie'=>999,'numero'=>1,'id_csc'=>'','csc'=>'']);
fiscalOk(\Cardapim\Services\Gerencia\FiscalService::configSimulacao(['serie'=>1,'numero'=>42,'id_csc'=>'1','csc'=>'segredo']) === ['serie'=>999,'numero'=>42,'id_csc'=>'','csc'=>'']);
