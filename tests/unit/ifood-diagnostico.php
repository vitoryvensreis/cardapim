<?php

declare(strict_types=1);

// Teste de log Merchant: resposta real e isolamento, sem vazar segredo no storage.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Ifood\IfoodDiagnostico;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

$erros = 0;
$ok = static function (bool $cond, string $nome) use (&$erros): void {
    if (!$cond) {
        fwrite(STDERR, "FALHOU: $nome\n");
        $erros++;
    }
};

$http = new class extends ClienteHttp {
    public RespostaHttp $retorno;

    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        return $this->retorno;
    }
};
$api = new IfoodApi($http);
$idRestaurante = 987654300;
$api->identificarRestaurante($idRestaurante);
$loja = '11111111-2222-3333-4444-555555555555';

$http->retorno = new RespostaHttp(201, json_encode([
    'id' => 'cccccccc-dddd-4eee-8fff-aaaaaaaaaaaa',
    'description' => 'Teste',
    'token' => 'SEGREDO_SUPERCONFIDENCIAL_1234',
    'customer' => ['email' => 'privado@example.com'],
], JSON_THROW_ON_ERROR));
$api->criarPausa('bearer-secreto', $loja, 'Teste', new DateTimeImmutable('2026-11-17 14:00:00'), new DateTimeImmutable('2026-11-17 15:00:00'));

$http->retorno = new RespostaHttp(200, json_encode([[
    'operation' => 'DELIVERY', 'available' => true, 'state' => 'OK',
    'validations' => [['code' => 'opening-hours', 'state' => 'OK']],
]], JSON_THROW_ON_ERROR));
$api->situacaoLoja('bearer-secreto', $loja);

$linhas = IfoodDiagnostico::recentes($idRestaurante, 10);
$ok(count($linhas) >= 2, 'registrou resposta POST e GET');
$ok(($linhas[0]['http'] ?? null) === 200 && ($linhas[0]['rota'] ?? '') === "/merchant/v1.0/merchants/{loja}/status", 'GET status registrado');
$ok(($linhas[1]['http'] ?? null) === 201 && ($linhas[1]['metodo'] ?? '') === 'POST', 'POST 201 registrado');
$log = json_encode($linhas, JSON_THROW_ON_ERROR);
$ok(!str_contains($log, 'SEGREDO_SUPERCONFIDENCIAL_1234') && !str_contains($log, 'bearer-secreto')
    && !str_contains($log, 'privado@example.com'), 'segredos e dados pessoais ausentes');
$ok(str_contains($log, 'opening-hours') && str_contains($log, '[oculto]'), 'validacoes preservadas e campos sensiveis ocultos');
$ok(IfoodDiagnostico::recentes($idRestaurante + 1) === [], 'nao exibe logs de outro restaurante');
$ok(IfoodDiagnostico::recentes(-1) === [], 'rejeita restaurante invalido');

foreach (glob(dirname(__DIR__, 2) . '/storage/logs/ifood-merchant/restaurante-' . $idRestaurante . '-*.jsonl') ?: [] as $arquivo) {
    @unlink($arquivo);
}
if ($erros > 0) {
    exit(1);
}
echo "OK: diagnostico Merchant seguro e isolado.\n";
