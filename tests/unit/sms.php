<?php

declare(strict_types=1);

// SMS de reserva do codigo de login (sem rede): cliente do SMSGate e troca automatica WhatsApp -> SMS.
// php tests/unit/sms.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\SmsGateApi;
use Cardapim\Services\Cliente\CanalCodigo;
use Cardapim\Services\Cliente\CodigoVerificacao;
use Cardapim\Services\Cliente\SmsPlataforma;
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

final class ClienteFalsoSms extends ClienteHttp
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

final class CanalFalso implements CanalCodigo
{
    /** @var list<array{string,string}> */
    public array $enviados = [];

    public function __construct(private readonly string $nome, private readonly bool $ligado = true, private readonly bool $falha = false)
    {
    }

    public function canal(): string
    {
        return $this->nome;
    }

    public function disponivel(): bool
    {
        return $this->ligado;
    }

    public function enviarCodigo(string $telefone, string $codigo): void
    {
        if ($this->falha) {
            throw new IntegracaoErro('falhou');
        }
        $this->enviados[] = [$telefone, $codigo];
    }
}

putenv('APP_URL=https://deliveryzim.com.br');
putenv('SMSGATE_URL=https://sms.exemplo/api/3rdparty/v1/');
putenv('SMSGATE_USUARIO=usuario-ci');
putenv('SMSGATE_SENHA=senha-ci');

/* ---------- Cliente do SMSGate ---------- */
$h = new ClienteFalsoSms([new RespostaHttp(202, '{"id":"abc123","state":"Pending"}')]);
$ok((new SmsGateApi($h))->enviar('5585999990000', 'Oi', 600) === 'abc123', 'id da mensagem');
$p = $h->pedidos[0];
$ok($p['metodo'] === 'POST' && $p['url'] === 'https://sms.exemplo/api/3rdparty/v1/messages', 'URL de envio');
$ok($p['cabecalhos']['Authorization'] === 'Basic ' . base64_encode('usuario-ci:senha-ci'), 'autenticacao basica');
$ok($p['corpo'] === ['textMessage' => ['text' => 'Oi'], 'phoneNumbers' => ['+5585999990000'], 'priority' => 100, 'ttl' => 600], 'corpo: E.164, prioridade urgente e validade');
$ok(str_contains((string) $erro(fn () => (new SmsGateApi(new ClienteFalsoSms([new RespostaHttp(401, '{}')])))->enviar('5585999990000', 'Oi')), 'usuário ou senha'), 'credenciais erradas');
$ok($erro(fn () => (new SmsGateApi(new ClienteFalsoSms([new RespostaHttp(500, '')])))->enviar('5585999990000', 'Oi')) !== null, 'falha do servidor vira erro');
$ok($erro(fn () => (new SmsGateApi(new ClienteFalsoSms([new RespostaHttp(202, '{}')])))->enviar('5585999990000', 'Oi')) !== null, 'resposta sem id vira erro');
$ok($erro(fn () => (new SmsGateApi(new ClienteFalsoSms([])))->enviar('123', 'Oi')) !== null, 'numero invalido recusado sem chamar o servidor');
putenv('SMSGATE_SENHA=');
$ok(!(new SmsGateApi())->configurado() && !(new SmsPlataforma())->disponivel(), 'sem senha = indisponivel');
putenv('SMSGATE_SENHA=senha-ci');

/* ---------- Texto do SMS ---------- */
$texto = SmsPlataforma::mensagem('123456');
$ok(str_starts_with($texto, '123456 e o seu codigo para entrar no '), 'codigo no inicio');
$ok(str_ends_with($texto, "\n\n@deliveryzim.com.br #123456"), 'ultima linha WebOTP com o dominio');
$ok(!str_contains($texto, '*') && preg_match('/[^\x00-\x7F]/', $texto) !== 1, 'sem formatacao e sem acentos');
$h = new ClienteFalsoSms([new RespostaHttp(202, '{"id":"x"}')]);
(new SmsPlataforma(new SmsGateApi($h)))->enviarCodigo('85999990000', '654321');
$ok($h->pedidos[0]['corpo']['phoneNumbers'] === ['+5585999990000'] && $h->pedidos[0]['corpo']['ttl'] === CodigoVerificacao::VALIDADE && str_contains($h->pedidos[0]['corpo']['textMessage']['text'], '654321'), 'canal SMS usa 55 + numero e a validade do codigo');

/* ---------- Troca automatica WhatsApp -> SMS ---------- */
$_SESSION = [];
$gerador = static fn (): string => '111222';
$ip = static fn (): string => '10.0.' . random_int(0, 255) . '.' . random_int(0, 255);
$numero = static fn (): string => '859' . random_int(10000000, 99999999);

$zap = new CanalFalso('whatsapp');
$sms = new CanalFalso('sms');
$r = (new CodigoVerificacao($zap, $gerador, $sms))->solicitar($numero(), $ip());
$ok($r['ok'] && $r['canal'] === 'whatsapp' && count($zap->enviados) === 1 && $sms->enviados === [], 'WhatsApp funcionando: nao manda SMS');

$_SESSION = [];
$zap = new CanalFalso('whatsapp', true, true);
$sms = new CanalFalso('sms');
$tel = $numero();
$r = (new CodigoVerificacao($zap, $gerador, $sms))->solicitar($tel, $ip());
$ok($r['ok'] && $r['canal'] === 'sms' && $sms->enviados === [[$tel, '111222']], 'WhatsApp falhou: mesmo codigo vai por SMS');
$ok(str_contains($r['mensagem'], 'SMS'), 'tela avisa que foi por SMS');
$v = (new CodigoVerificacao($zap, $gerador, $sms))->verificar('111222');
$ok($v['ok'] && $v['telefone'] === $tel, 'codigo enviado por SMS e aceito');

$_SESSION = [];
$zap = new CanalFalso('whatsapp', false);
$sms = new CanalFalso('sms');
$r = (new CodigoVerificacao($zap, $gerador, $sms))->solicitar($numero(), $ip());
$ok($r['ok'] && $r['canal'] === 'sms' && $zap->enviados === [], 'WhatsApp desconectado: vai direto por SMS');

$_SESSION = [];
$r = (new CodigoVerificacao(new CanalFalso('whatsapp', true, true), $gerador, new CanalFalso('sms', true, true)))->solicitar($numero(), $ip());
$ok(!$r['ok'] && $r['status'] === 503, 'os dois falharam: erro claro');

$_SESSION = [];
$c = new CodigoVerificacao(new CanalFalso('whatsapp', false), $gerador, new CanalFalso('sms', false));
$r = $c->solicitar($numero(), $ip());
$ok(!$c->disponivel() && !$r['ok'] && $r['status'] === 503, 'nenhum canal ligado: login indisponivel');
$ok((new CodigoVerificacao(new CanalFalso('whatsapp', false), $gerador, new CanalFalso('sms')))->disponivel(), 'so o SMS ligado: login disponivel');
$ok((new CodigoVerificacao(new CanalFalso('whatsapp'), $gerador))->disponivel(), 'sem SMS configurado continua funcionando so com WhatsApp');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s)\n");
    exit(1);
}
echo "OK: SMS de reserva do codigo de login (SMSGate e troca automatica).\n";
