<?php

declare(strict_types=1);

// Contrato Merchant: POST 201 com ID gerado pelo iFood; DELETE 204 sem corpo.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

$erros = 0;
$verificar = static function (bool $condicao, string $mensagem) use (&$erros): void {
    if (!$condicao) {
        fwrite(STDERR, "FALHOU: $mensagem\n");
        $erros++;
    }
};
$exigeFalha = static function (callable $acao, string $mensagem) use ($verificar): void {
    try {
        $acao();
        $verificar(false, $mensagem);
    } catch (IntegracaoErro) {
        $verificar(true, $mensagem);
    }
};

$http = new class extends ClienteHttp {
    public RespostaHttp $resposta;
    /** @var list<array{metodo:string,url:string,corpo:array|string|null}> */
    public array $chamadas = [];

    public function enviar(string $metodo, string $url, array $cabecalhos = [], array|string|null $corpo = null): RespostaHttp
    {
        $this->chamadas[] = ['metodo' => $metodo, 'url' => $url, 'corpo' => $corpo];
        return $this->resposta;
    }
};

$api = new IfoodApi($http);
$loja = '11111111-2222-3333-4444-555555555555';
$id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$inicio = new DateTimeImmutable('2026-11-17T14:00:00-03:00');
$fim = new DateTimeImmutable('2026-11-17T15:00:00-03:00');

$http->resposta = new RespostaHttp(201, json_encode(['id' => $id], JSON_THROW_ON_ERROR));
$verificar($api->criarPausa('token-falso', $loja, 'Teste de pausa', $inicio, $fim) === $id, 'retorna ID do iFood');
$chamada = $http->chamadas[0];
$verificar($chamada['metodo'] === 'POST' && str_ends_with($chamada['url'], "/merchants/$loja/interruptions"), 'rota e metodo de criacao');
$corpo = $chamada['corpo'];
$verificar(is_array($corpo) && !array_key_exists('id', $corpo), 'POST nao envia ID gerado pelo Deliveryzim');
$verificar(is_array($corpo) && $corpo['description'] === 'Teste de pausa'
    && $corpo['start'] === '2026-11-17T17:00:00Z'
    && $corpo['end'] === '2026-11-17T18:00:00Z', 'datas UTC e descricao oficiais');

$http->resposta = new RespostaHttp(201, '{}');
$exigeFalha(fn (): string => $api->criarPausa('token-falso', $loja, 'Teste', $inicio, $fim), '201 sem ID nao gera pausa ficticia');
$http->resposta = new RespostaHttp(200, json_encode(['id' => $id], JSON_THROW_ON_ERROR));
$exigeFalha(fn (): string => $api->criarPausa('token-falso', $loja, 'Teste', $inicio, $fim), '200 inesperado nao confirma criacao');
$http->resposta = new RespostaHttp(202, json_encode(['id' => $id], JSON_THROW_ON_ERROR));
$exigeFalha(fn (): string => $api->criarPausa('token-falso', $loja, 'Teste', $inicio, $fim), '202 pendente nao confirma criacao');

$http->resposta = new RespostaHttp(204, '');
$api->removerPausa('token-falso', $loja, $id);
$chamada = end($http->chamadas);
$verificar($chamada['metodo'] === 'DELETE' && str_ends_with($chamada['url'], "/interruptions/$id"), 'DELETE usa ID retornado pelo iFood');
$http->resposta = new RespostaHttp(200, '{}');
$exigeFalha(fn (): null => $api->removerPausa('token-falso', $loja, $id), '200 inesperado nao confirma remocao');
$http->resposta = new RespostaHttp(403, '{}');
$exigeFalha(fn (): null => $api->removerPausa('token-falso', $loja, $id), '403 rejeitado');

// Pausa rapida "so o iFood": periodo calculado no horario de Brasilia, 10 s a frente.
$zonaBr = new DateTimeZone('America/Sao_Paulo');
$meioDia = new DateTimeImmutable('2026-11-19 12:00:00', $zonaBr);
$p30 = \Cardapim\Services\Gerencia\Integracoes\IfoodLojaService::periodoRapido('30min', $meioDia);
$verificar($p30 === ['inicio' => '2026-11-19T12:00:10', 'fim' => '2026-11-19T12:30:10'], 'pausa rapida de 30 minutos');
$p2 = \Cardapim\Services\Gerencia\Integracoes\IfoodLojaService::periodoRapido('2h', $meioDia);
$verificar(($p2['fim'] ?? '') === '2026-11-19T14:00:10', 'pausa rapida de 2 horas');
$pDia = \Cardapim\Services\Gerencia\Integracoes\IfoodLojaService::periodoRapido('fim_do_dia', $meioDia);
$verificar(($pDia['fim'] ?? '') === '2026-11-19T23:59:59', 'pausa ate o fim do dia');
$utc = new DateTimeImmutable('2026-11-19 15:00:00', new DateTimeZone('UTC')); // 12:00 em Brasilia
$verificar((\Cardapim\Services\Gerencia\Integracoes\IfoodLojaService::periodoRapido('1h', $utc)['inicio'] ?? '') === '2026-11-19T12:00:10', 'relogio em UTC vira Brasilia');
$quaseMeiaNoite = new DateTimeImmutable('2026-11-19 23:58:55', $zonaBr);
$verificar(\Cardapim\Services\Gerencia\Integracoes\IfoodLojaService::periodoRapido('fim_do_dia', $quaseMeiaNoite) === null, 'menos de 1 minuto para o fim do dia recusa');
$verificar(\Cardapim\Services\Gerencia\Integracoes\IfoodLojaService::periodoRapido('3dias', $meioDia) === null, 'opcao desconhecida recusa');

if ($erros !== 0) {
    exit(1);
}
echo "OK: contrato Merchant para pausas validado.\n";
