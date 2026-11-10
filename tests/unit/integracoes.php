<?php

declare(strict_types=1);

// Teste sem banco e sem rede das integracoes: php tests/unit/integracoes.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;
use Cardapim\Shared\Validation\Documento;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};
$erro = static function (callable $f): ?string {
    try {
        $f();
    } catch (IntegracaoErro $e) {
        return $e->getMessage();
    }
    return null;
};

/** Cliente HTTP falso: devolve respostas prontas e guarda o que foi pedido. */
final class ClienteFalso extends ClienteHttp
{
    /** @var list<array{metodo:string,url:string,cabecalhos:array<string,string>,corpo:mixed}> */
    public array $pedidos = [];

    /** @param list<RespostaHttp> $respostas */
    public function __construct(private array $respostas)
    {
        parent::__construct(1);
    }

    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        $this->pedidos[] = compact('metodo', 'url', 'cabecalhos', 'corpo');
        return array_shift($this->respostas) ?? new RespostaHttp(500, '');
    }
}

/* ---------- Asaas ---------- */
putenv('ASAAS_URL_SANDBOX=https://sandbox.exemplo/v3');
putenv('ASAAS_URL_PRODUCAO=https://producao.exemplo/v3');
$http = new ClienteFalso([new RespostaHttp(200, '{"balance":1234.5}')]);
$ok((new AsaasApi($http))->saldo('$aact_x', 'sandbox') === '1234.50', 'saldo do Asaas');
$ok($http->pedidos[0]['url'] === 'https://sandbox.exemplo/v3/finance/balance', 'URL do sandbox');
$ok($http->pedidos[0]['cabecalhos']['access_token'] === '$aact_x' && $http->pedidos[0]['cabecalhos']['User-Agent'] !== '', 'cabecalhos access_token e User-Agent');
$ok(AsaasApi::urlBase('producao') === 'https://producao.exemplo/v3', 'URL de producao');
$msg = $erro(fn () => (new AsaasApi(new ClienteFalso([new RespostaHttp(401, '{"errors":[{"code":"invalid_access_token"}]}')])))->saldo('x', 'sandbox'));
$ok(is_string($msg) && str_contains($msg, 'recusou a chave'), 'chave invalida vira mensagem clara');
$msg = $erro(fn () => (new AsaasApi(new ClienteFalso([new RespostaHttp(400, '{"errors":[{"description":"URL inacessivel"}]}')])))->criarWebhook('x', 'sandbox', 'https://a', str_repeat('a', 40), ''));
$ok(is_string($msg) && str_contains($msg, 'URL inacessivel'), 'erro do Asaas repassado');
$http = new ClienteFalso([new RespostaHttp(200, '{"id":"wh_1"}')]);
$ok((new AsaasApi($http))->criarWebhook('x', 'sandbox', 'https://c.test/webhooks/asaas/abc', str_repeat('b', 48), 'a@b.c') === 'wh_1', 'cria webhook');
$corpo = $http->pedidos[0]['corpo'];
$ok(is_array($corpo) && $corpo['authToken'] === str_repeat('b', 48) && $corpo['sendType'] === 'SEQUENTIALLY' && in_array('PAYMENT_RECEIVED', $corpo['events'], true), 'corpo do webhook');

/* ---------- WPPConnect ---------- */
putenv('WPPCONNECT_BASE_URL=http://127.0.0.1:21466');
putenv('WPPCONNECT_SECRET_KEY=segredo/teste');
$ok(WppConnectApi::disponivel(), 'WPPConnect configurado');
$http = new ClienteFalso([new RespostaHttp(201, '{"status":"success","token":"tok123"}')]);
$ok((new WppConnectApi($http))->gerarToken('cardapim_r1_ab12') === 'tok123', 'token gerado');
$ok($http->pedidos[0]['url'] === 'http://127.0.0.1:21466/api/cardapim_r1_ab12/segredo%2Fteste/generate-token', 'segredo codificado na URL');
$ok($erro(fn () => WppConnectApi::sessao('../etc')) !== null, 'sessao com caracteres invalidos recusada');
$http = new ClienteFalso([new RespostaHttp(200, '{"status":"qrcode","qrcode":"data:image/png;base64,AAAA"}')]);
$e = (new WppConnectApi($http))->estado('cardapim_r1_ab12', 'tok');
$ok($e['status'] === 'QRCODE' && $e['qrcode'] === 'data:image/png;base64,AAAA', 'estado com QR Code');
$e = (new WppConnectApi(new ClienteFalso([new RespostaHttp(200, '{"status":"CONNECTED","qrcode":"javascript:alert(1)"}')])))->estado('cardapim_r1_ab12', 'tok');
$ok($e['status'] === 'CONNECTED' && $e['qrcode'] === null, 'QR que nao e imagem e descartado');
$ok((new WppConnectApi(new ClienteFalso([new RespostaHttp(200, '{"response":{"wid":{"user":"5585988887777"}}}')])))->numero('cardapim_r1_ab12', 't') === '5585988887777', 'numero do aparelho');
$ok($erro(fn () => (new WppConnectApi(new ClienteFalso([new RespostaHttp(200, '{"status":"error"}')])))->enviarTexto('cardapim_r1_ab12', 't', '5585999990000', 'oi')) !== null, 'falha no envio vira erro');

/* ---------- iFood ---------- */
putenv('IFOOD_CLIENT_ID=app-id');
putenv('IFOOD_CLIENT_SECRET=app-segredo');
putenv('IFOOD_BASE_URL=https://ifood.exemplo');
$http = new ClienteFalso([new RespostaHttp(200, '{"userCode":"ABCD-EFGH","authorizationCodeVerifier":"v1","verificationUrlComplete":"https://portal.ifood.com.br/apps/code?c=ABCD-EFGH","expiresIn":600}')]);
$v = (new IfoodApi($http))->codigoVinculo();
$ok($v['codigo'] === 'ABCD-EFGH' && $v['verificador'] === 'v1' && $v['expira'] > time(), 'codigo de vinculo');
$ok($http->pedidos[0]['url'] === 'https://ifood.exemplo/authentication/v1.0/oauth/userCode' && str_contains((string) $http->pedidos[0]['corpo'], 'clientId=app-id'), 'pedido do codigo de vinculo');
$http = new ClienteFalso([new RespostaHttp(200, '{"accessToken":"acc","refreshToken":"ref","expiresIn":10800}')]);
$t = (new IfoodApi($http))->trocarCodigo('AUTH', 'v1');
$ok($t['acesso'] === 'acc' && $t['renovacao'] === 'ref', 'troca do codigo de autorizacao');
parse_str((string) $http->pedidos[0]['corpo'], $form);
$ok($form['grantType'] === 'authorization_code' && $form['authorizationCodeVerifier'] === 'v1' && $form['clientSecret'] === 'app-segredo', 'campos do token');
$lojas = (new IfoodApi(new ClienteFalso([new RespostaHttp(200, '[{"id":"11111111-2222-3333-4444-555555555555","name":"Loja"},{"id":"<script>","name":"x"}]')])))->lojas('acc');
$ok(count($lojas) === 1 && $lojas[0]['nome'] === 'Loja', 'lojas validas (id estranho descartado)');
$s = (new IfoodApi(new ClienteFalso([new RespostaHttp(200, '[{"state":"CLOSED","available":false,"message":{"title":"Fora do horario"}}]')])))->statusLoja('acc', 'id');
$ok($s['aberta'] === false && $s['mensagem'] === 'Fora do horario', 'status da loja');
$ok($erro(fn () => (new IfoodApi(new ClienteFalso([new RespostaHttp(400, '{}')])))->trocarCodigo('X', 'v')) !== null, 'codigo recusado vira erro');

/* ---------- Telefone ---------- */
$ok(Documento::formatarTelefone('5585999990000') === '(85) 99999-0000', 'telefone com 55 formatado');
$ok(Documento::formatarTelefone('85999990000') === '(85) 99999-0000', 'telefone sem 55');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: integracoes (Asaas, WPPConnect e iFood sem rede).\n";
