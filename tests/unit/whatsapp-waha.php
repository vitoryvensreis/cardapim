<?php

declare(strict_types=1);

// WAHA (motor GOWS) sem rede: cliente HTTP falso, escolha do servidor, assinatura e conversao do webhook.
// php tests/unit/whatsapp-waha.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WahaApi;
use Cardapim\Modules\Integracoes\WebhookDeMensagens;
use Cardapim\Modules\Integracoes\WhatsAppProvedores as P;
use Cardapim\Services\Pedidos\SaudacaoWhatsApp;
use Cardapim\Services\WhatsApp\EventoWaha as E;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
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

final class ClienteFalsoWaha extends ClienteHttp
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
$falso = static fn (RespostaHttp ...$r): ClienteFalsoWaha => new ClienteFalsoWaha($r);

putenv('APP_URL=https://deliveryzim.com.br');
putenv('EVOLUTION_BASE_URL=https://wpp.exemplo/');
putenv('EVOLUTION_API_KEY=chave-evolution');
putenv('WAHA_URL=https://waha.exemplo/');
putenv('WAHA_API_KEY=chave-waha');
putenv('WAHA_WEBHOOK_TOKEN=' . str_repeat('b', 40));

/* ---------- Escolha do servidor ---------- */
putenv('WHATSAPP_PROVEDOR_PADRAO');
$ok(P::padrao()->id() === 'evolution', 'sem WHATSAPP_PROVEDOR_PADRAO, conexoes novas continuam na Evolution');
putenv('WHATSAPP_PROVEDOR_PADRAO=waha');
$ok(P::padrao()->id() === 'waha', 'WHATSAPP_PROVEDOR_PADRAO=waha: conexoes novas no WAHA');
$ok(P::daIntegracao(['provedor' => 'waha'])->id() === 'waha', 'loja conectada no WAHA usa o WAHA');
$ok(P::daIntegracao(['provedor' => 'evolution'])->id() === 'evolution' && P::daIntegracao([])->id() === 'evolution', 'loja antiga (ou sem provedor) continua na Evolution');
$ok(count(P::configurados()) === 2, 'os dois servidores configurados');
$ok(P::recebimentoConfigurado(), 'recebimento configurado pelo WAHA');
$ok((new WahaApi()) instanceof WebhookDeMensagens, 'WAHA ajusta o recebimento pelo painel');

/* ---------- Criar sessao ---------- */
$h = $falso(new RespostaHttp(201, '{"name":"deliveryzim_r6_ab12cd34","status":"STOPPED"}'));
$token = (new WahaApi($h))->criarSessao('deliveryzim_r6_ab12cd34');
$p = $h->pedidos[0];
$ok(preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'token da sessao gerado aqui (64 hex)');
$ok($p['metodo'] === 'POST' && $p['url'] === 'https://waha.exemplo/api/sessions', 'URL de criacao');
$ok($p['cabecalhos']['X-Api-Key'] === 'chave-waha', 'chave do servidor no X-Api-Key');
$ok($p['corpo']['name'] === 'deliveryzim_r6_ab12cd34' && $p['corpo']['start'] === false, 'sessao criada parada (o QR sai no iniciar)');
$w = $p['corpo']['config']['webhooks'][0] ?? [];
$ok(($w['url'] ?? '') === 'https://deliveryzim.com.br/webhooks/waha/' . str_repeat('b', 40) && ($w['events'] ?? []) === ['message'], 'webhook da loja: so mensagens recebidas');
$ok(($w['hmac']['key'] ?? '') === $token, 'webhook assinado com o token da loja');
$h = $falso(new RespostaHttp(201, '{}'));
(new WahaApi($h))->criarSessao('deliveryzim_plataforma_ab12cd34');
$ok($h->pedidos[0]['corpo']['config']['webhooks'] === [], 'sessao da plataforma sem webhook');
$ok($erro(fn () => (new WahaApi($falso(new RespostaHttp(401, '{}'))))->criarSessao('deliveryzim_r6_ab12cd34')) !== null, 'chave errada vira erro claro');
$ok($erro(fn () => (new WahaApi($falso()))->criarSessao('../etc')) !== null, 'nome invalido recusado antes de chamar');

/* ---------- Iniciar e situacao ---------- */
$h = $falso(new RespostaHttp(201, '{}'));
(new WahaApi($h))->iniciar('deliveryzim_r6_ab12cd34', $token);
$ok($h->pedidos[0]['metodo'] === 'POST' && $h->pedidos[0]['url'] === 'https://waha.exemplo/api/sessions/deliveryzim_r6_ab12cd34/start', 'iniciar = start');
$api = static fn (RespostaHttp ...$r): WahaApi => new WahaApi($falso(...$r));
$ok($api(new RespostaHttp(200, '{"status":"WORKING"}'))->estado('deliveryzim_r6_ab12cd34', $token) === ['status' => 'CONNECTED', 'qrcode' => null], 'WORKING = conectado');
$e = $api(new RespostaHttp(200, '{"status":"SCAN_QR_CODE"}'), new RespostaHttp(200, '{"mimetype":"image/png","data":"iVBORw0KGgo="}'))->estado('deliveryzim_r6_ab12cd34', $token);
$ok($e === ['status' => 'QRCODE', 'qrcode' => 'data:image/png;base64,iVBORw0KGgo='], 'SCAN_QR_CODE devolve o QR em base64');
$ok($api(new RespostaHttp(200, '{"status":"SCAN_QR_CODE"}'), new RespostaHttp(500, ''))->estado('x_sessao', $token)['status'] === 'INITIALIZING', 'QR ainda nao pronto = preparando');
$ok($api(new RespostaHttp(200, '{"status":"STARTING"}'))->estado('x_sessao', $token)['status'] === 'INITIALIZING', 'STARTING = preparando');
$ok($api(new RespostaHttp(200, '{"status":"PASSKEY_REQUIRED"}'))->estado('x_sessao', $token)['status'] === 'INITIALIZING', 'passkey pendente = preparando');
$ok($api(new RespostaHttp(200, '{"status":"FAILED"}'))->estado('x_sessao', $token)['status'] === 'CLOSED', 'FAILED = desconectado');
$ok($api(new RespostaHttp(200, '{"status":"STOPPED"}'))->estado('x_sessao', $token)['status'] === 'CLOSED', 'STOPPED = desconectado');
$ok($api(new RespostaHttp(404, '{}'))->estado('x_sessao', $token)['status'] === 'CLOSED', 'sessao apagada = desconectado');
$ok($api(new RespostaHttp(200, '{"status":"WORKING","me":{"id":"558588811631@c.us","pushName":"Mana"}}'))->numero('x_sessao', $token) === '558588811631', 'numero do aparelho');
$ok($api(new RespostaHttp(200, '{"status":"WORKING","me":null}'))->numero('x_sessao', $token) === null, 'sem aparelho, sem numero');

/* ---------- Enviar ---------- */
$h = $falso(new RespostaHttp(200, '{"numberExists":true,"chatId":"558588811631@c.us"}'), new RespostaHttp(201, '{"id":"true_558588811631@c.us_ABC"}'));
(new WahaApi($h))->enviarTexto('deliveryzim_r6_ab12cd34', $token, '5585988811631', 'Oi');
$ok(str_starts_with($h->pedidos[0]['url'], 'https://waha.exemplo/api/contacts/check-exists?phone=5585988811631&session=deliveryzim_r6_ab12cd34'), 'confere a conversa certa do numero (9 extra)');
$ok($h->pedidos[1]['url'] === 'https://waha.exemplo/api/sendText' && $h->pedidos[1]['corpo'] === ['session' => 'deliveryzim_r6_ab12cd34', 'chatId' => '558588811631@c.us', 'text' => 'Oi'], 'envia para a conversa devolvida pelo WhatsApp');
$ok($erro(fn () => (new WahaApi($falso(new RespostaHttp(200, '{"numberExists":false}'))))->enviarTexto('x_sessao', $token, '5585999999999', 'Oi')) === 'Este número não tem WhatsApp.', 'numero sem WhatsApp vira erro');
$h = $falso(new RespostaHttp(500, ''), new RespostaHttp(201, '{}'));
(new WahaApi($h))->enviarTexto('x_sessao', $token, '5585988811631', 'Oi');
$ok($h->pedidos[1]['corpo']['chatId'] === '5585988811631@c.us', 'consulta fora do ar: usa o numero como veio');
$h = $falso(new RespostaHttp(201, '{}'));
(new WahaApi($h))->enviarTexto('x_sessao', $token, '123456789012345@lid', 'Oi');
$ok(count($h->pedidos) === 1 && $h->pedidos[0]['corpo']['chatId'] === '123456789012345@lid', 'contato com LID: envia direto, sem consultar');
$ok($erro(fn () => (new WahaApi($falso(new RespostaHttp(200, '{"numberExists":true,"chatId":"558588811631@c.us"}'), new RespostaHttp(500, ''))))->enviarTexto('x_sessao', $token, '5585988811631', 'Oi')) !== null, 'falha no envio vira erro');

/* ---------- Webhook da sessao ---------- */
$url = 'https://deliveryzim.com.br/webhooks/waha/' . str_repeat('b', 40);
$ok($api(new RespostaHttp(200, json_encode(['config' => ['webhooks' => [['url' => $url, 'events' => ['message']]]]])))->situacaoWebhook('x_sessao', $token) === 'ok', 'webhook certo = ok');
$ok($api(new RespostaHttp(200, json_encode(['config' => ['webhooks' => [['url' => 'https://outro', 'events' => ['message']]]]])))->situacaoWebhook('x_sessao', $token) === 'errado', 'outro endereco = errado');
$ok($api(new RespostaHttp(200, '{"config":{"webhooks":[]}}'))->situacaoWebhook('x_sessao', $token) === 'errado', 'sem webhook = errado');
$ok($api(new RespostaHttp(503, ''))->situacaoWebhook('x_sessao', $token) === 'desconhecido', 'servidor sem resposta = desconhecido');
$h = $falso(new RespostaHttp(200, '{}'));
(new WahaApi($h))->configurarWebhook('deliveryzim_r6_ab12cd34', $token);
$ok($h->pedidos[0]['metodo'] === 'PUT' && $h->pedidos[0]['corpo']['config']['webhooks'][0]['hmac']['key'] === $token, 'corrigir grava o webhook assinado de novo');

/* ---------- Sair, listar e limpeza ---------- */
$h = $falso(new RespostaHttp(201, '{}'), new RespostaHttp(200, '{}'));
$ok((new WahaApi($h))->sair('deliveryzim_r6_ab12cd34', $token) && $h->pedidos[0]['url'] === 'https://waha.exemplo/api/sessions/deliveryzim_r6_ab12cd34/logout' && $h->pedidos[1]['metodo'] === 'DELETE', 'sair = logout + apagar');
$ok((new WahaApi($falso(new RespostaHttp(404, ''))))->encerrar('deliveryzim_r6_ab12cd34'), 'sessao ja apagada conta como encerrada');
$ok((new WahaApi($falso(new RespostaHttp(200, '[{"name":"deliveryzim_r6_ab12cd34"},{"name":"teste"},{"name":"../x"}]'))))->sessoesNoServidor() === ['deliveryzim_r6_ab12cd34', 'teste'], 'lista as sessoes (nomes validos)');

/* ---------- Assinatura ---------- */
$corpo = '{"event":"message","session":"deliveryzim_r6_ab12cd34"}';
$ok(WahaApi::assinaturaValida($corpo, $token, hash_hmac('sha512', $corpo, $token)), 'assinatura certa aceita');
$ok(WahaApi::assinaturaValida($corpo, $token, strtoupper(hash_hmac('sha512', $corpo, $token))), 'assinatura em maiusculas aceita');
$ok(!WahaApi::assinaturaValida($corpo . ' ', $token, hash_hmac('sha512', $corpo, $token)), 'corpo alterado recusado');
$ok(!WahaApi::assinaturaValida($corpo, $token, null) && !WahaApi::assinaturaValida($corpo, '', 'x'), 'sem assinatura ou sem token recusado');

/* ---------- Conversao do webhook ---------- */
$msg = static fn (array $payload, string $evento = 'message'): array => ['id' => 'evt_1', 'event' => $evento, 'session' => 'deliveryzim_r6_ab12cd34', 'payload' => $payload];
$c = E::paraSaudacao($msg(['from' => '5585988811631@c.us', 'fromMe' => false, 'timestamp' => 1795864000]));
$ok($c === ['event' => 'onmessage', 'session' => 'deliveryzim_r6_ab12cd34', 'from' => '5585988811631@c.us', 'fromMe' => false, 'isGroupMsg' => false, 'isStatus' => false, 't' => 1795864000], 'mensagem recebida convertida');
$ok(SaudacaoWhatsApp::destinatarioDireto($c) === '5585988811631', 'saudacao aceita o numero do WAHA');
$ok(E::paraSaudacao($msg(['from' => '5585988811631@c.us', 'fromMe' => false, 'timestamp' => 1795864000123]))['t'] === 1795864000, 'timestamp em milissegundos vira segundos');
$ok(E::paraSaudacao($msg(['from' => '558588811631@s.whatsapp.net', 'fromMe' => false, 'timestamp' => 1]))['from'] === '558588811631@c.us', '@s.whatsapp.net vira @c.us');
$lid = E::paraSaudacao($msg(['from' => '217763549327461@lid', 'fromMe' => false, 'timestamp' => 1, '_data' => ['Info' => ['SenderAlt' => '558588428661@s.whatsapp.net']]]));
$ok($lid['from'] === '558588428661@c.us', 'LID com numero alternativo usa o numero');
$ok(E::paraSaudacao($msg(['from' => '217763549327461@lid', 'fromMe' => false, 'timestamp' => 1]))['from'] === '217763549327461@lid', 'LID sem numero segue como LID');
$ok(SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($msg(['from' => '217763549327461@lid', 'fromMe' => false, 'timestamp' => 1]))) === '217763549327461@lid', 'saudacao aceita LID do WAHA');
$ok(E::paraSaudacao($msg(['from' => '120363000000000000@g.us', 'fromMe' => false, 'timestamp' => 1]))['isGroupMsg'] === true, 'grupo marcado');
$ok(E::paraSaudacao($msg(['from' => 'status@broadcast', 'fromMe' => false, 'timestamp' => 1]))['isStatus'] === true, 'status marcado');
$ok(E::paraSaudacao($msg(['from' => '5585988811631@c.us', 'fromMe' => true, 'timestamp' => 1]))['fromMe'] === true, 'mensagem da propria loja marcada');
$ok(E::paraSaudacao($msg(['from' => '5585988811631@c.us'], 'session.status')) === null, 'outros eventos ignorados');
$ok(E::paraSaudacao(['event' => 'message', 'session' => '', 'payload' => ['from' => 'x']]) === null && E::paraSaudacao(['event' => 'message', 'session' => 's', 'payload' => []]) === null, 'evento incompleto ignorado');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) no WAHA.\n");
    exit(1);
}
echo "OK: WAHA (servidor, sessao, envio, webhook assinado e conversao).\n";
