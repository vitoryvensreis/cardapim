<?php

declare(strict_types=1);

// Evolution API sem rede: cliente HTTP falso, escolha do servidor e conversao do webhook.
// php tests/unit/whatsapp-evolution.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\EvolutionApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WhatsAppProvedores as P;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Services\Pedidos\SaudacaoWhatsApp;
use Cardapim\Services\WhatsApp\EventoEvolution as E;
use Cardapim\Services\WhatsApp\SessoesAbandonadas;
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

final class ClienteFalsoEvolution extends ClienteHttp
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
$falso = static fn (RespostaHttp ...$r): ClienteFalsoEvolution => new ClienteFalsoEvolution($r);

putenv('APP_URL=https://deliveryzim.com.br');
putenv('EVOLUTION_BASE_URL=https://wpp.exemplo/');
putenv('EVOLUTION_API_KEY=chave-global');
putenv('EVOLUTION_WEBHOOK_TOKEN=' . str_repeat('a', 40));

/* ---------- Nomes e escolha do servidor ---------- */
$nova = P::novaSessao(7);
$ok(preg_match('/\Adeliveryzim_r7_[a-f0-9]{8}\z/', $nova) === 1, 'sessao nova de loja usa deliveryzim_r{id}_');
$ok(preg_match('/\Adeliveryzim_plataforma_[a-f0-9]{8}\z/', P::novaSessao(null)) === 1, 'sessao nova da plataforma');
$ok(P::doDeliveryzim($nova) && P::doDeliveryzim('cardapim_r2_50f28385') && P::doDeliveryzim('cardapim_plataforma_f90007fc'), 'nomes novos e antigos reconhecidos');
$ok(!P::doDeliveryzim('teste-escotilha') && !P::doDeliveryzim('taskesc-suporte') && !P::doDeliveryzim('deliveryzim_r0_50f28385') && !P::doDeliveryzim('outro_deliveryzim_r2_50f28385'), 'instancias de outros projetos ficam de fora');
$ok(P::restauranteDaSessao('deliveryzim_r42_abcdef01') === 42 && P::restauranteDaSessao('cardapim_r3_abcdef01') === 3, 'restaurante pelo nome');
$ok(P::restauranteDaSessao('deliveryzim_plataforma_abcdef01') === null && P::restauranteDaSessao('qualquer') === null, 'plataforma e nomes invalidos sem restaurante');
$ok(P::daIntegracao([])->id() === 'wppconnect' && P::daIntegracao(['provedor' => 'evolution'])->id() === 'evolution', 'sessao sem provedor gravado continua no WPPConnect');
$ok($erro(fn () => P::sessao('../etc')) !== null, 'nome com caracteres invalidos recusado');

putenv('WPPCONNECT_BASE_URL=');
putenv('WPPCONNECT_SECRET_KEY=');
putenv('WHATSAPP_PROVEDOR_PADRAO=evolution');
$ok(P::padrao()->id() === 'evolution', 'padrao Evolution');
putenv('WHATSAPP_PROVEDOR_PADRAO=wppconnect');
$ok(P::padrao()->id() === 'evolution', 'padrao WPPConnect sem configuracao cai na Evolution');
$ok(count(P::configurados()) === 1 && P::algumDisponivel(), 'so a Evolution configurada');
putenv('WPPCONNECT_BASE_URL=http://127.0.0.1:21465');
putenv('WPPCONNECT_SECRET_KEY=segredo');
$ok(P::padrao()->id() === 'wppconnect' && count(P::configurados()) === 2, 'padrao WPPConnect quando configurado');
putenv('WHATSAPP_PROVEDOR_PADRAO=evolution');
$ok((new WppConnectApi())->configurado() && (new EvolutionApi())->configurado(), 'os dois configurados');

/* ---------- Criar instancia ---------- */
$h = $falso(new RespostaHttp(201, '{"instance":{"instanceName":"deliveryzim_r1_ab12cd34","status":"connecting"},"hash":"tok-instancia"}'));
$ok((new EvolutionApi($h))->criarSessao('deliveryzim_r1_ab12cd34') === 'tok-instancia', 'token da instancia (hash texto)');
$p = $h->pedidos[0];
$ok($p['metodo'] === 'POST' && $p['url'] === 'https://wpp.exemplo/instance/create', 'URL de criacao');
$ok($p['cabecalhos']['apikey'] === 'chave-global', 'criacao usa a chave global');
$ok($p['corpo']['instanceName'] === 'deliveryzim_r1_ab12cd34' && $p['corpo']['integration'] === 'WHATSAPP-BAILEYS', 'nome e canal Baileys');
$ok($p['corpo']['groupsIgnore'] === true && $p['corpo']['syncFullHistory'] === false && $p['corpo']['alwaysOnline'] === false && $p['corpo']['readMessages'] === false && $p['corpo']['rejectCall'] === false, 'ajustes padrao da loja');
$ok($p['corpo']['webhook']['url'] === 'https://deliveryzim.com.br/webhooks/evolution/' . str_repeat('a', 40) && $p['corpo']['webhook']['events'] === ['MESSAGES_UPSERT'], 'webhook da instancia');
$ok((new EvolutionApi($falso(new RespostaHttp(201, '{"hash":{"apikey":"tok-v1"}}'))))->criarSessao('deliveryzim_r1_ab12cd34') === 'tok-v1', 'hash no formato antigo');
$ok(str_contains((string) $erro(fn () => (new EvolutionApi($falso(new RespostaHttp(401, '{}'))))->criarSessao('deliveryzim_r1_ab12cd34')), 'chave do servidor'), 'chave global errada');
$ok($erro(fn () => (new EvolutionApi($falso(new RespostaHttp(403, '{"response":{"message":["already in use"]}}'))))->criarSessao('deliveryzim_r1_ab12cd34')) !== null, 'nome ja usado vira erro');
putenv('EVOLUTION_WEBHOOK_TOKEN=curto');
$h = $falso(new RespostaHttp(201, '{"hash":"t"}'));
(new EvolutionApi($h))->criarSessao('deliveryzim_r1_ab12cd34');
$ok(!isset($h->pedidos[0]['corpo']['webhook']), 'sem segredo valido nao registra webhook');
putenv('EVOLUTION_WEBHOOK_TOKEN=' . str_repeat('a', 40));

/* ---------- Iniciar, estado e numero ---------- */
$h = $falso(new RespostaHttp(200, '{"base64":"data:image/png;base64,AAA","code":"x"}'));
(new EvolutionApi($h))->iniciar('deliveryzim_r1_ab12cd34', 'tok');
$ok($h->pedidos[0]['metodo'] === 'GET' && $h->pedidos[0]['url'] === 'https://wpp.exemplo/instance/connect/deliveryzim_r1_ab12cd34' && $h->pedidos[0]['cabecalhos']['apikey'] === 'tok', 'iniciar com o token da loja');
$ok($erro(fn () => (new EvolutionApi($falso(new RespostaHttp(500, ''))))->iniciar('deliveryzim_r1_ab12cd34', 'tok')) !== null, 'falha ao iniciar vira erro');

$e = (new EvolutionApi($falso(new RespostaHttp(200, '{"instance":{"instanceName":"x","state":"open"}}'))))->estado('deliveryzim_r1_ab12cd34', 'tok');
$ok($e === ['status' => 'CONNECTED', 'qrcode' => null], 'open = conectado');
$e = (new EvolutionApi($falso(new RespostaHttp(200, '{"instance":{"state":"connecting"}}'), new RespostaHttp(200, '{"base64":"data:image/png;base64,QRQR"}'))))->estado('deliveryzim_r1_ab12cd34', 'tok');
$ok($e === ['status' => 'QRCODE', 'qrcode' => 'data:image/png;base64,QRQR'], 'connecting com QR Code');
$e = (new EvolutionApi($falso(new RespostaHttp(200, '{"instance":{"state":"connecting"}}'), new RespostaHttp(200, '{"base64":"javascript:alert(1)"}'))))->estado('deliveryzim_r1_ab12cd34', 'tok');
$ok($e === ['status' => 'INITIALIZING', 'qrcode' => null], 'QR Code que nao e imagem e descartado');
$ok((new EvolutionApi($falso(new RespostaHttp(200, '{"instance":{"state":"close"}}'))))->estado('deliveryzim_r1_ab12cd34', 'tok')['status'] === 'CLOSED', 'close = fechado');
$ok((new EvolutionApi($falso(new RespostaHttp(404, '{}'))))->estado('deliveryzim_r1_ab12cd34', 'tok')['status'] === 'CLOSED', 'instancia apagada = fechado');
$ok((new EvolutionApi($falso()))->respondendo('deliveryzim_r1_ab12cd34', 'tok') === null, 'sem verificacao de travamento');
$ok((new EvolutionApi($falso(new RespostaHttp(200, '[{"name":"deliveryzim_r1_ab12cd34","ownerJid":"5585988887777@s.whatsapp.net"}]'))))->numero('deliveryzim_r1_ab12cd34', 'tok') === '5585988887777', 'numero do aparelho');
$ok((new EvolutionApi($falso(new RespostaHttp(200, '[]'))))->numero('deliveryzim_r1_ab12cd34', 'tok') === null, 'sem numero');

/* ---------- Envio ---------- */
$h = $falso(new RespostaHttp(201, '{"key":{"id":"ABC","fromMe":true},"status":"PENDING"}'));
(new EvolutionApi($h))->enviarTexto('deliveryzim_r1_ab12cd34', 'tok', '5585999990000', 'Oi');
$ok($h->pedidos[0]['url'] === 'https://wpp.exemplo/message/sendText/deliveryzim_r1_ab12cd34' && $h->pedidos[0]['corpo'] === ['number' => '5585999990000', 'text' => 'Oi'], 'envio de texto');
$h = $falso(new RespostaHttp(201, '{"key":{"id":"ABC"}}'));
(new EvolutionApi($h))->enviarTexto('deliveryzim_r1_ab12cd34', 'tok', '1234567890123456@lid', 'Oi');
$ok($h->pedidos[0]['corpo']['number'] === '1234567890123456@lid', 'contato LID enviado como identificador');
$ok($erro(fn () => (new EvolutionApi($falso(new RespostaHttp(400, '{"response":{"message":[{"exists":false}]}}'))))->enviarTexto('deliveryzim_r1_ab12cd34', 'tok', '5585999990000', 'Oi')) !== null, 'numero sem WhatsApp vira erro');
$ok($erro(fn () => (new EvolutionApi($falso(new RespostaHttp(200, '{}'))))->enviarTexto('deliveryzim_r1_ab12cd34', 'tok', '5585999990000', 'Oi')) !== null, 'resposta sem confirmacao vira erro');

/* ---------- Sair, listar e encerrar ---------- */
$h = $falso(new RespostaHttp(200, '{}'), new RespostaHttp(200, '{}'));
$ok((new EvolutionApi($h))->sair('deliveryzim_r1_ab12cd34', 'tok') === true, 'sair confirmado');
$ok($h->pedidos[0]['metodo'] === 'DELETE' && str_ends_with($h->pedidos[0]['url'], '/instance/logout/deliveryzim_r1_ab12cd34') && $h->pedidos[0]['cabecalhos']['apikey'] === 'tok', 'logout com o token da loja');
$ok(str_ends_with($h->pedidos[1]['url'], '/instance/delete/deliveryzim_r1_ab12cd34') && $h->pedidos[1]['cabecalhos']['apikey'] === 'chave-global', 'apagar com a chave global');
$ok((new EvolutionApi($falso(new RespostaHttp(500, ''), new RespostaHttp(500, ''))))->sair('deliveryzim_r1_ab12cd34', 'tok') === false, 'servidor nao confirmou');
$lista = (new EvolutionApi($falso(new RespostaHttp(200, '[{"name":"deliveryzim_r1_ab12cd34"},{"name":"teste-escotilha"},{"instance":{"instanceName":"cardapim_r2_50f28385"}},{"name":"../x"}]'))))->sessoesNoServidor();
$ok($lista === ['deliveryzim_r1_ab12cd34', 'teste-escotilha', 'cardapim_r2_50f28385'], 'lista de instancias do servidor');
$ok($erro(fn () => (new EvolutionApi($falso(new RespostaHttp(401, '{}'))))->sessoesNoServidor()) !== null, 'falha ao listar vira erro');
$ok((new EvolutionApi($falso(new RespostaHttp(404, '{}'), new RespostaHttp(404, '{}'))))->encerrar('deliveryzim_r9_aaaaaaaa') === true, 'encerrar instancia que ja nao existe');
$d = SessoesAbandonadas::decidir(['deliveryzim_r1_ab12cd34', 'deliveryzim_r9_aaaaaaaa', 'teste-escotilha', 'taskesc-suporte'], ['deliveryzim_r1_ab12cd34'], ['deliveryzim_r9_aaaaaaaa' => 1], 1000);
$ok($d['fechar'] === ['deliveryzim_r9_aaaaaaaa'] && $d['aguardando'] === [], 'limpeza so fecha instancias abandonadas do Deliveryzim');

putenv('EVOLUTION_API_KEY=');
$ok(!(new EvolutionApi())->configurado() && $erro(fn () => (new EvolutionApi($falso()))->iniciar('deliveryzim_r1_ab12cd34', 'tok')) !== null, 'sem chave global = indisponivel');
putenv('EVOLUTION_API_KEY=chave-global');

/* ---------- Webhook (messages.upsert) ---------- */
$agora = time();
$base = ['event' => 'messages.upsert', 'instance' => 'deliveryzim_r1_ab12cd34', 'apikey' => 'tok-instancia',
    'data' => ['key' => ['remoteJid' => '5585999990000@s.whatsapp.net', 'fromMe' => false, 'id' => 'X'], 'messageTimestamp' => $agora, 'message' => ['conversation' => 'oi']]];
$ev = E::paraSaudacao($base);
$ok($ev === ['event' => 'onmessage', 'session' => 'deliveryzim_r1_ab12cd34', 'from' => '5585999990000@c.us', 'fromMe' => false, 'isGroupMsg' => false, 'isStatus' => false, 't' => $agora], 'mensagem recebida convertida');
$ok(SaudacaoWhatsApp::destinatarioDireto($ev) === '5585999990000', 'saudacao entende o evento convertido');
$ok(E::tokenInstancia($base) === 'tok-instancia' && E::tokenInstancia([]) === null, 'token da instancia no corpo');
$ok(E::paraSaudacao(['event' => 'MESSAGES_UPSERT'] + $base)['event'] === 'onmessage', 'nome do evento em maiusculas');
$ok(E::paraSaudacao(['event' => 'connection.update'] + $base) === null, 'outros eventos ignorados');
$ok(E::paraSaudacao(array_diff_key($base, ['instance' => 1])) === null, 'sem instancia ignorado');
$proprio = $base;
$proprio['data']['key']['fromMe'] = true;
$ok(SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($proprio)) === null, 'mensagem da propria loja ignorada');
$grupo = $base;
$grupo['data']['key']['remoteJid'] = '120363000000000000@g.us';
$ok(E::paraSaudacao($grupo)['isGroupMsg'] === true && SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($grupo)) === null, 'grupo ignorado');
$status = $base;
$status['data']['key']['remoteJid'] = 'status@broadcast';
$ok(SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($status)) === null, 'status ignorado');
$lid = $base;
$lid['data']['key']['remoteJid'] = '1234567890123456@lid';
$ok(E::paraSaudacao($lid)['from'] === '1234567890123456@lid' && SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($lid)) === '1234567890123456@lid', 'LID sem numero real segue como LID');
$lid['data']['key']['remoteJidAlt'] = '5585988887777@s.whatsapp.net';
$ok(SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($lid)) === '5585988887777', 'LID com numero real usa o numero');
$lid['data']['key'] = ['remoteJid' => '1234567890123456@lid', 'fromMe' => false, 'senderPn' => '5585977776666'];
$ok(SaudacaoWhatsApp::destinatarioDireto(E::paraSaudacao($lid)) === '5585977776666', 'senderPn so com digitos');
$longo = $base;
$longo['data']['messageTimestamp'] = ['low' => $agora, 'high' => 0, 'unsigned' => false];
$ok(E::paraSaudacao($longo)['t'] === $agora, 'timestamp no formato Long');
$texto = $base;
$texto['data']['messageTimestamp'] = (string) $agora;
$ok(E::paraSaudacao($texto)['t'] === $agora, 'timestamp em texto');
$listaEv = $base;
$listaEv['data'] = [$base['data']];
$ok(E::paraSaudacao($listaEv)['from'] === '5585999990000@c.us', 'data em lista');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s)\n");
    exit(1);
}
echo "OK: WhatsApp Evolution (escolha do servidor, cliente sem rede e webhook).\n";
