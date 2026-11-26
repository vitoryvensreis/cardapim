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
// OpenAPI NeXT: ClienteIncluir autentica só com SH + token; o CNPJ do emitente vai no corpo.
fiscalOk($h->pedidos[0][2]['CNPJCliente'] === '11444777000161' && $h->pedidos[0][2]['RazaoSocial'] === 'Teste' && $h->pedidos[0][1]['X-CnpjCliente'] === '11222333000181' && !isset($h->pedidos[0][1]['X-Ambiente']));
$a->certificadoUpload('11222333000181','11444777000161','individual','pfx','senha','Teste','CE','csc','1');
fiscalOk($h->pedidos[1][1]['X-Ambiente'] === '2' && $h->pedidos[1][2]['DocBin'] === base64_encode('pfx'));
$a->configHomologacao('11222333000181','11444777000161','individual','Teste','CE','csc','1');
fiscalOk($h->pedidos[2][2]['FormaEmissao'] === 1 && $h->pedidos[2][1]['X-Ambiente'] === '2');
fiscalOk($a->statusCliente('11222333000181','11444777000161','individual',3) === 107);
// Produção: a API aceita o ambiente 1; quem bloqueia é a liberação da plataforma (FiscalAcesso::producaoLiberada).
fiscalOk($a->statusCliente('11222333000181','11444777000161','individual',1) === 107 && end($h->pedidos)[1]['X-Ambiente'] === '1');
try { $a->statusCliente('11222333000181','11444777000161','individual',4); throw new LogicException('Ambiente inválido aceito'); } catch (RuntimeException) {}
$a->configAmbiente('11222333000181','11444777000161','individual','Teste','CE','csc','1',1);
fiscalOk(end($h->pedidos)[1]['X-Ambiente'] === '1');
try { $a->configAmbiente('11222333000181','11444777000161','individual','Teste','CE','csc','1',3); throw new LogicException('Config em simulação aceita'); } catch (RuntimeException) {}
try { CertificadoA1::validar('invalido','senha','11444777000161'); throw new LogicException('Certificado inválido aceito'); } catch (RuntimeException) {}
fiscalOk(CertificadoA1::alerta(time()+6*86400) === 'Vence em até 7 dias');
fiscalOk(CertificadoA1::alerta(time()+14*86400) === 'Vence em até 15 dias');
fiscalOk(CertificadoA1::alerta(time()+29*86400) === 'Vence em até 30 dias');
echo "Testes fiscais sem rede aprovados.\n";

$sim = \Cardapim\Services\Gerencia\FiscalService::configSimulacao([]);
fiscalOk($sim === ['serie'=>999,'numero'=>1,'id_csc'=>'','csc'=>'']);
fiscalOk(\Cardapim\Services\Gerencia\FiscalService::configSimulacao(['serie'=>1,'numero'=>42,'id_csc'=>'1','csc'=>'segredo']) === ['serie'=>999,'numero'=>42,'id_csc'=>'','csc'=>'']);

final class FiscalGtinFake extends ClienteHttp {
    public array $pedido=[];
    public function enviar(string $metodo,string $url,array $cabecalhos=[],array|string|null $corpo=null): RespostaHttp {
        $this->pedido=[$metodo,$url,$cabecalhos,$corpo];
        return new RespostaHttp(200,'{"sucesso":true,"dados":{"CodStatus":9490,"XProd":"Produto teste","NCM":"22021000","CEST":"0300700","Token":"nao-repassar"}}');
    }
}
$gtinFake=new FiscalGtinFake();
$gtin=(new NextFiscalApi($gtinFake))->gtin('11222333000181','11444777000161','individual','7894900011517');
fiscalOk($gtinFake->pedido[0]==='GET' && str_ends_with($gtinFake->pedido[1],'nfe/GTINConsulta?EAN=7894900011517') && $gtinFake->pedido[2]['X-Ambiente']==='2' && $gtinFake->pedido[2]['X-Token']==='individual' && $gtinFake->pedido[3]===null);
fiscalOk($gtin['ncm']==='22021000' && $gtin['cest']==='0300700' && !isset($gtin['Token']));
try { NextFiscalApi::filtrarGtin(['CodStatus'=>9491,'NCM'=>'22021000','XProd'=>'Teste']); throw new LogicException('GTIN não localizado aceito.'); } catch (RuntimeException) {}

// Certificado A1 em formato antigo (RC2/3DES): OpenSSL 3 recusa sem o provedor legacy; o sistema converte só para validar.
$dirA1 = sys_get_temp_dir() . '/cardapim-a1-' . bin2hex(random_bytes(4));
@mkdir($dirA1, 0700);
$gerou = trim((string) shell_exec('cd ' . escapeshellarg($dirA1) . ' && openssl req -x509 -newkey rsa:2048 -nodes -keyout k.pem -out c.pem -days 30 -subj "/CN=EMPRESA TESTE:11444777000161" >/dev/null 2>&1 && openssl pkcs12 -export -legacy -inkey k.pem -in c.pem -out legado.pfx -passout pass:1234 >/dev/null 2>&1 && echo ok'));
if ($gerou === 'ok') {
    $meta = CertificadoA1::validar((string) file_get_contents($dirA1 . '/legado.pfx'), '1234', '11444777000161');
    fiscalOk(($meta['expira_em'] ?? 0) > time());
    try { CertificadoA1::validar((string) file_get_contents($dirA1 . '/legado.pfx'), 'errada', '11444777000161'); throw new LogicException('Senha errada aceita'); }
    catch (RuntimeException $e) { fiscalOk(str_contains($e->getMessage(), 'senha')); }
    echo "Certificado A1 legado validado.\n";
}
array_map('unlink', glob($dirA1 . '/*') ?: []);
@rmdir($dirA1);
