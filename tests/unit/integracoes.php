<?php

declare(strict_types=1);

// Teste sem banco e sem rede das integracoes: php tests/unit/integracoes.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Plataforma\CobrancaAssinaturas as Cobranca;
use Cardapim\Modules\Integracoes\AplicativosParceiros;
use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\GoogleRoutesApi;
use Cardapim\Repositories\Plataforma\GoogleMapsRepository;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Modules\Integracoes\IntegracaoErro;
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

/* ---------- Fiscal NeXT (sem rede) ---------- */
$nextHttp = new ClienteFalso([
    new RespostaHttp(200, '{"sucesso":true,"mensagem":"","dados":{}}'),
    new RespostaHttp(200, '{"sucesso":true,"dados":{"ArqIniClientes":{"Clientes":{"Quantidade":"1"},"Cliente1":{"CNPJ":"11222333000181","RazaoSocial":"Empresa teste","Token":"segredo-do-emitente","SoftwareHouse":"1"}}}}'),
]);
$next = new \Cardapim\Modules\Integracoes\NextFiscalApi($nextHttp);
$next->disponibilidade('11222333000181', 'token-falso-adm', 3);
$lista = $next->clientes('11222333000181', 'token-falso-adm');
$ok($lista['quantidade'] === 1 && $lista['clientes'][0]['cnpj'] === '11222333000181' && $lista['clientes'][0]['software_house'], 'NeXT: lista administrativa');
$ok(!str_contains(json_encode($lista), 'segredo-do-emitente') && !isset($lista['clientes'][0]['Token']), 'NeXT: nunca propaga token do emitente');
$ok($nextHttp->pedidos[0]['url'] === 'https://www.apinext.com.br/ws/v1/nfe/Disponibilidade' && $nextHttp->pedidos[0]['cabecalhos']['X-Ambiente'] === '3', 'NeXT: disponibilidade em simulacao');
$ok($nextHttp->pedidos[1]['url'] === 'https://www.apinext.com.br/ws/v1/clientes/ClientesListar' && !isset($nextHttp->pedidos[1]['cabecalhos']['X-Ambiente']), 'NeXT: consulta administrativa sem ambiente');
try {
    $next->disponibilidade('11222333000181', 'token-falso-adm', 1);
    $ok(false, 'NeXT: producao deve ser bloqueada');
} catch (\RuntimeException) {
    $ok(count($nextHttp->pedidos) === 2, 'NeXT: bloqueio antes de chamada de producao');
}
try {
    \Cardapim\Modules\Integracoes\NextFiscalApi::filtrarClientes(['ArqIniClientes' => ['Clientes' => ['Quantidade' => '2']]]);
    $ok(false, 'NeXT: lista incompleta deve falhar');
} catch (\RuntimeException) {
    $ok(true, 'NeXT: lista incompleta rejeitada');
}
try {
    $recusado = new \Cardapim\Modules\Integracoes\NextFiscalApi(new ClienteFalso([new RespostaHttp(403, '{"mensagem":"segredo-adm"}')]));
    $recusado->clientes('11222333000181', 'token-falso-adm');
    $ok(false, 'NeXT: permissao recusada deve falhar');
} catch (\RuntimeException $e) {
    $ok(!str_contains($e->getMessage(), 'segredo-adm'), 'NeXT: erro sanitizado');
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

/* WhatsApp (WAHA): tests/unit/whatsapp-waha.php */

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

/* ---------- Credenciais dos apps nos marketplaces (sem banco: vale o .env) ---------- */
AplicativosParceiros::esquecer();
$c = AplicativosParceiros::credenciais('ifood');
$ok($c === ['client_id' => 'app-id', 'client_secret' => 'app-segredo', 'origem' => 'env'], 'iFood sem banco usa o .env');
$ok(AplicativosParceiros::existe('99food') && !AplicativosParceiros::existe('xpto'), 'lista fechada de marketplaces');
putenv('NOVENOVEFOOD_APP_ID');
putenv('NOVENOVEFOOD_CLIENT_SECRET');
$ok(!AplicativosParceiros::disponivel('99food'), '99Food sem credenciais nao fica disponivel');
$recusou = false;
try {
    AplicativosParceiros::credenciais('../x');
} catch (InvalidArgumentException) {
    $recusou = true;
}
$ok($recusou, 'provedor desconhecido recusado');

/* ---------- Google Routes API: sem rede nem consumo ---------- */
$http = new ClienteFalso([new RespostaHttp(200, '{"routes":[{"distanceMeters":3456}]}')]);
$km = (new GoogleRoutesApi('chave_teste_nao_real', $http))->distanciaKm(-3.73, -38.52, 'Rua Teste 100, Fortaleza, CE, Brasil');
$ok(abs($km - 3.456) < 0.00001, 'Google Routes: converte metros para quilometros sem arredondar a faixa');
$requisicao = $http->pedidos[0];
$ok($requisicao['metodo'] === 'POST' && $requisicao['url'] === 'https://routes.googleapis.com/directions/v2:computeRoutes',
    'Google Routes: endpoint oficial e POST');
$ok($requisicao['cabecalhos']['X-Goog-FieldMask'] === 'routes.distanceMeters'
    && $requisicao['cabecalhos']['X-Goog-Api-Key'] === 'chave_teste_nao_real', 'Google Routes: mascara e chave so no backend');
$ok($requisicao['corpo']['origin']['location']['latLng']['latitude'] === -3.73
    && $requisicao['corpo']['destination']['address'] === 'Rua Teste 100, Fortaleza, CE, Brasil'
    && $requisicao['corpo']['travelMode'] === 'DRIVE'
    && $requisicao['corpo']['routingPreference'] === 'TRAFFIC_UNAWARE', 'Google Routes: origem, destino e modo basico');
foreach ([
    ['resposta' => new RespostaHttp(200, '{"routes":[]}'), 'descricao' => 'sem rota'],
    ['resposta' => new RespostaHttp(200, '{"routes":[{"distanceMeters":-1}]}'), 'descricao' => 'distancia negativa'],
    ['resposta' => new RespostaHttp(403, '{}'), 'descricao' => 'chave sem permissao'],
    ['resposta' => new RespostaHttp(429, '{}'), 'descricao' => 'cota excedida'],
    ['resposta' => new RespostaHttp(503, '{}'), 'descricao' => 'Google indisponivel'],
] as $caso) {
    $falhouRota = false;
    try {
        (new GoogleRoutesApi('chave_teste', new ClienteFalso([$caso['resposta']])))->distanciaKm(-3.73, -38.52, 'Rua Teste 100, Fortaleza, CE, Brasil');
    } catch (RuntimeException) {
        $falhouRota = true;
    }
    $ok($falhouRota, 'Google Routes: recusa ' . $caso['descricao']);
}
/* ---------- Diagnostico Google: codigos oficiais, mensagens seguras e sem segredo ---------- */
$casosGoogle = [
    [403, 'PERMISSION_DENIED', 'API_KEY_IP_ADDRESS_BLOCKED', 'IP de origem'],
    [403, 'PERMISSION_DENIED', 'API_KEY_HTTP_REFERRER_BLOCKED', 'referenciadores HTTP'],
    [403, 'PERMISSION_DENIED', 'API_KEY_SERVICE_BLOCKED', 'não tem permissão'],
    [403, 'PERMISSION_DENIED', 'SERVICE_DISABLED', 'desativada'],
    [403, 'PERMISSION_DENIED', 'BILLING_DISABLED', 'faturamento'],
    [403, 'PERMISSION_DENIED', 'API_KEY_INVALID', 'inválida'],
    [429, 'RESOURCE_EXHAUSTED', 'RATE_LIMIT_EXCEEDED', 'cota'],
    [429, 'RESOURCE_EXHAUSTED', 'RESOURCE_QUOTA_EXCEEDED', 'cota'],
    [403, 'PERMISSION_DENIED', 'CONSUMER_SUSPENDED', 'bloqueado'],
];
foreach ($casosGoogle as [$httpStatus, $googleStatus, $motivo, $esperado]) {
    $corpoErro = json_encode(['error' => [
        'code' => $httpStatus,
        'status' => $googleStatus,
        // Simula resposta de terceiro que inclui segredo: NUNCA exibir ou registrar.
        'message' => 'Google provider response, key=AIzaTESTE_SECRETO_NUNCA_EXIBIR',
        'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $motivo]],
    ]], JSON_THROW_ON_ERROR);
    $diagnostico = null;
    try {
        (new GoogleRoutesApi('chave_teste', new ClienteFalso([new RespostaHttp($httpStatus, $corpoErro)])))
            ->distanciaKm(-3.73, -38.52, 'Rua Teste 100, Fortaleza, CE, Brasil');
    } catch (IntegracaoErro $ex) {
        $diagnostico = $ex->getMessage();
    }
    $ok(is_string($diagnostico) && str_contains($diagnostico, $esperado)
        && str_contains($diagnostico, $motivo) && str_contains($diagnostico, 'HTTP ' . $httpStatus),
        'Google Routes: diagnostico seguro de ' . $motivo);
    $ok(is_string($diagnostico) && !str_contains($diagnostico, 'AIzaTESTE_SECRETO_NUNCA_EXIBIR'),
        'Google Routes: nao revela resposta crua de ' . $motivo);
}
$casosSemReason = [
    [403, '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"Permission denied"}}', 'motivo específico não foi informado'],
    [400, '{"error":{"code":400,"status":"INVALID_ARGUMENT","message":"Origin and destination must be set."}}', 'parâmetros'],
    [401, '{"error":{"code":401,"status":"UNAUTHENTICATED","message":"Not authenticated"}}', 'autenticação'],
    [404, '{"error":{"code":404,"status":"NOT_FOUND","message":"Not found"}}', 'não encontrou'],
    [429, '{"error":{"code":429,"status":"RESOURCE_EXHAUSTED","message":"Quota"}}', 'limite'],
    [503, '{"error":{"code":503,"status":"UNAVAILABLE","message":"Temporarily unavailable"}}', 'indisponível'],
    [504, '{"error":{"code":504,"status":"DEADLINE_EXCEEDED","message":"Deadline"}}', 'demorou'],
    [403, '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"API key not valid. Please pass a valid API key."}}', 'inválida'],
    [403, '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"Billing has not been enabled on the project."}}', 'faturamento'],
    [403, '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"Requests from IP address 192.0.2.1 are blocked."}}', 'IP de origem'],
];
foreach ($casosSemReason as [$httpStatus, $corpo, $esperado]) {
    $diagnostico = null;
    try {
        (new GoogleRoutesApi('chave_teste', new ClienteFalso([new RespostaHttp($httpStatus, $corpo)])))
            ->distanciaKm(-3.73, -38.52, 'Rua Teste 100, Fortaleza, CE, Brasil');
    } catch (IntegracaoErro $ex) {
        $diagnostico = $ex->getMessage();
    }
    $ok(is_string($diagnostico) && str_contains($diagnostico, $esperado),
        'Google Routes: erro por status HTTP ' . $httpStatus . ' sem ErrorInfo');
}
// Nunca repetir valores arbitrarios ou codigo HTML vindo do provedor no painel.
$injetado = '{"error":{"code":403,"status":"PERMISSION_DENIED","message":"<script>alert(1)</script> secretAIza123","details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"<script>"}]}}';
$msgSegura = '';
try {
    (new GoogleRoutesApi('chave_teste', new ClienteFalso([new RespostaHttp(403, $injetado)])))
        ->distanciaKm(-3.73, -38.52, 'Rua Teste 100, Fortaleza, CE, Brasil');
} catch (IntegracaoErro $ex) {
    $msgSegura = $ex->getMessage();
}
$ok(!str_contains($msgSegura, '<script>') && !str_contains($msgSegura, 'secretAIza123')
    && str_contains($msgSegura, 'HTTP 403'), 'Google Routes: protecao contra XSS e vazamento da resposta');

// SQLite em memoria: configuracao unica, criptografada e desligada por padrao.
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $dbGoogle = new PDO('sqlite::memory:');
    $dbGoogle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbGoogle->exec('CREATE TABLE configuracoes (chave TEXT PRIMARY KEY, valor TEXT, tipo TEXT, descricao TEXT)');
    Cripto::usarChave(str_repeat('g', 32));
    $repoGoogle = new GoogleMapsRepository($dbGoogle);
    $ok($repoGoogle->buscar() === null, 'Google Routes: sem cadastro nao existe configuracao ativa');
    $repoGoogle->salvar(['chave' => 'chave_sigilo_teste', 'ativo' => false, 'testado_em' => '', 'salvo_em' => '2026-11-22', 'salvo_por' => 'Admin']);
    $cifrado = (string) $dbGoogle->query("SELECT valor FROM configuracoes WHERE chave = 'google_routes_global'")->fetchColumn();
    $ok(!str_contains($cifrado, 'chave_sigilo_teste'), 'Google Routes: chave protegida no banco');
    $ok($repoGoogle->buscar()['ativo'] === false, 'Google Routes: integracao desligada por padrao');
    $repoGoogle->salvar(['chave' => 'chave_sigilo_teste', 'ativo' => true, 'testado_em' => '2026-11-22', 'salvo_em' => '2026-11-22', 'salvo_por' => 'Admin']);
    $ok($repoGoogle->buscar()['ativo'] === true, 'Google Routes: ativacao global persistida');
    $repoGoogle->remover();
    $ok($repoGoogle->buscar() === null, 'Google Routes: remocao restaura configuracao ausente');
    Cripto::usarChave(null);
}

/* ---------- Telefone ---------- */
$ok(Documento::formatarTelefone('5585999990000') === '(85) 99999-0000', 'telefone com 55 formatado');
$ok(Documento::formatarTelefone('85999990000') === '(85) 99999-0000', 'telefone sem 55');

/* ---------- Cobranca das assinaturas (Asaas da plataforma) ---------- */
$regras = Cobranca::PADRAO;
$ok(Cobranca::tipoAsaas(['pix', 'boleto']) === 'UNDEFINED', 'varias formas: restaurante escolhe na fatura');
$ok(Cobranca::tipoAsaas(['boleto']) === 'BOLETO' && Cobranca::tipoAsaas(['pix']) === 'PIX' && Cobranca::tipoAsaas(['cartao']) === 'CREDIT_CARD', 'uma forma so');
$ok(Cobranca::CICLOS['mensal'] === 'MONTHLY' && Cobranca::CICLOS['semestral'] === 'SEMIANNUALLY' && Cobranca::CICLOS['anual'] === 'YEARLY', 'ciclos do plano');
$hojeTeste = new DateTimeImmutable('2026-11-11');
$ok(Cobranca::primeiroVencimento($regras, $hojeTeste) === '2026-11-14', 'primeira cobranca em 3 dias');
$ok(Cobranca::primeiroVencimento(['teste_dias' => 7] + $regras, $hojeTeste) === '2026-11-18', 'dias gratis adiam a primeira cobranca');
$enc = Cobranca::encargosAsaas($regras);
$ok($enc['fine'] === ['value' => 2.0, 'type' => 'PERCENTAGE'] && $enc['interest'] === ['value' => 1.0] && !isset($enc['discount']), 'multa e juros padrao, sem desconto');
$enc = Cobranca::encargosAsaas(['desconto_percentual' => '5.00', 'desconto_dias' => 3, 'multa_percentual' => '0.00'] + $regras);
$ok(!isset($enc['fine']) && $enc['discount'] === ['value' => 5.0, 'dueDateLimitDays' => 3, 'type' => 'PERCENTAGE'], 'desconto ate N dias antes');
$ok(str_starts_with(Cobranca::resumo($regras), 'Pix ou Boleto; 1ª cobrança em 3 dias; multa 2%; juros 1% ao mês; suspende após 7 dias'), 'resumo das regras');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: integracoes (Asaas e iFood sem rede).\n";
