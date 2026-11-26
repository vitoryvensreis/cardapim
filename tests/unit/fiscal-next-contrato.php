<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/bootstrap/app.php';

use Cardapim\Modules\Fiscal\MensagemFiscal;
use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Modules\Integracoes\NextFiscalErro;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

/** Simula a NeXT e confere cada chamada contra a OpenAPI (https://www.apinext.com.br/ws/v1/openapi.json). */
final class NextContratoFalso extends ClienteHttp
{
    public array $pedidos = [];
    public array $respostas = [];
    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        $this->pedidos[] = ['metodo'=>$metodo,'url'=>$url,'h'=>$cabecalhos,'corpo'=>$corpo];
        return array_shift($this->respostas) ?? new RespostaHttp(200, '{"sucesso":true,"mensagem":"","dados":{}}');
    }
}
function contrato(bool $v, string $m): void { if (!$v) { throw new RuntimeException('Contrato NeXT: ' . $m); } }

$sh = '11222333000181'; $cli = '11444777000161';
$http = new NextContratoFalso();
$api = new NextFiscalApi($http);

// Campos de corpo permitidos por método (OpenAPI).
$corpos = [
    'clientes/ClienteIncluir' => ['CNPJCliente','RazaoSocial'],
    'nfe/Config' => ['UF','CSC','IdCSC','NomeEmpresa','FormaEmissao'],
    'nfe/CertificadoUpload' => ['DocBin','NomeEmpresa','UF','CSC','TokenCSC','ArquivoCertificado','SenhaCertificado'],
];

$http->respostas[] = new RespostaHttp(200, '{"sucesso":true,"dados":{"TokenCliente":"tok-cliente"}}');
contrato($api->incluirCliente($sh, 'adm', $cli, 'Restaurante') === 'tok-cliente', 'token do ClienteIncluir');
$api->configHomologacao($sh, $cli, 'tok', 'Restaurante', 'CE', 'csc', '000001');
$api->certificadoUpload($sh, $cli, 'tok', 'pfx', 'senha', 'Restaurante', 'CE', 'csc', '000001');
foreach ($http->pedidos as $p) {
    $metodo = substr($p['url'], strlen('https://www.apinext.com.br/ws/v1/'));
    contrato(str_starts_with($p['url'], 'https://www.apinext.com.br/ws/v1/'), 'URL base');
    contrato($p['h']['X-CnpjSh'] === $sh && $p['h']['X-Token'] !== '', 'cabeçalhos de credencial em ' . $metodo);
    $extras = array_diff(array_keys($p['corpo']), $corpos[$metodo]);
    contrato($extras === [], 'campos fora da OpenAPI em ' . $metodo . ': ' . implode(',', $extras));
}
contrato($http->pedidos[0]['corpo']['CNPJCliente'] === $cli, 'CNPJ do emitente no corpo do ClienteIncluir');
contrato($http->pedidos[1]['h']['X-CnpjCliente'] === $cli && $http->pedidos[1]['h']['X-Ambiente'] === '2', 'Config em homologação com o emitente');
contrato($http->pedidos[2]['corpo']['DocBin'] === base64_encode('pfx'), 'certificado em base64');

// ClientesListar: token do emitente lido no servidor.
$http->pedidos = [];
$http->respostas[] = new RespostaHttp(200, json_encode(['sucesso'=>true,'dados'=>['ArqIniClientes'=>[
    'Clientes'=>['Quantidade'=>'2'],
    'Cliente1'=>['CNPJ'=>$sh,'RazaoSocial'=>'SH','Token'=>'tok-sh','SoftwareHouse'=>'1'],
    'Cliente2'=>['CNPJ'=>$cli,'RazaoSocial'=>'Rest','Token'=>'tok-certo','SoftwareHouse'=>'0'],
]]]));
contrato($api->tokenDoCliente($sh, 'adm', $cli) === 'tok-certo', 'token do emitente no ClientesListar');
contrato(!isset($http->pedidos[0]['h']['X-Ambiente']), 'ClientesListar sem ambiente');
$http->respostas[] = new RespostaHttp(200, '{"sucesso":true,"dados":{"ArqIniClientes":{"Clientes":{"Quantidade":"0"}}}}');
contrato($api->tokenDoCliente($sh, 'adm', $cli) === null, 'emitente ausente da conta');

// Rejeição dentro do método (200 + sucesso=false) e erro fora do método ({"erro":{...}}).
$http->respostas[] = new RespostaHttp(200, '{"sucesso":false,"mensagem":"Senha do certificado incorreta","dados":null}');
try { $api->certificadoUpload($sh, $cli, 'tok', 'pfx', 'x', 'R', 'CE', 'c', '1'); contrato(false, 'rejeição aceita'); }
catch (NextFiscalErro $e) { contrato($e->paraUsuario() === 'A NeXT informou: Senha do certificado incorreta', 'mensagem da rejeição'); }
$http->respostas[] = new RespostaHttp(400, '{"erro":{"codigo":"PARAMETRO_INVALIDO","mensagem":"CNPJCliente obrigatório","campo":"CNPJCliente"}}');
try { $api->incluirCliente($sh, 'adm', $cli, 'R'); contrato(false, 'erro 400 aceito'); }
catch (NextFiscalErro $e) { contrato($e->codigoFornecedor === 'PARAMETRO_INVALIDO' && str_contains($e->getMessage(), '(campo CNPJCliente)'), 'erro fora do método'); }
$http->respostas[] = new RespostaHttp(401, '');
try { $api->statusCliente($sh, $cli, 'tok', 3); contrato(false, '401 aceito'); }
catch (RuntimeException $e) { contrato(MensagemFiscal::para($e, 'x') !== 'x', '401 traduzido'); }

// Texto do fornecedor: sem controles e sem trechos longos que pareçam credenciais.
contrato(NextFiscalApi::textoFornecedor("Linha\nnova " . str_repeat('A', 40)) === 'Linha nova ***', 'higienização');
contrato(MensagemFiscal::para(new RuntimeException('SQLSTATE[HY000] segredo'), 'padrão') === 'padrão', 'mensagem interna não exposta');

echo "Contrato NeXT (OpenAPI) conferido sem rede.\n";
