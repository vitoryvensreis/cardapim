<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * Routes API v2: somente distancia pelas ruas, sem mapa nem polyline.
 * Respostas de erro do Google sao convertidas em instrucoes seguras para o ADM Master.
 * Nunca repassar o JSON bruto do provedor (pode conter credenciais e dados de enderecos).
 * https://developers.google.com/maps/documentation/routes/handle-errors
 */
final class GoogleRoutesApi
{
    private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct(private readonly string $chave, private readonly ?ClienteHttp $http = null)
    {
    }

    /** Distancia para UMA origem e UM destino por ruas; nao guardar o resultado Google em cache persistente. */
    public function distanciaKm(float $origemLat, float $origemLon, string $destino): float
    {
        if (!Distancia::coordenadaValida($origemLat, $origemLon) || trim($destino) === '' || mb_strlen($destino) > 500) {
            throw new IntegracaoErro('Endereço de origem ou destino inválido para calcular a rota.');
        }

        $resposta = ($this->http ?? new ClienteHttp(6))->enviar('POST', self::ENDPOINT, [
            'X-Goog-Api-Key' => $this->chave,
            'X-Goog-FieldMask' => 'routes.distanceMeters',
        ], [
            'origin' => ['location' => ['latLng' => ['latitude' => $origemLat, 'longitude' => $origemLon]]],
            'destination' => ['address' => trim($destino)],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'regionCode' => 'br',
        ]);

        if (!$resposta->ok()) {
            throw self::interpretarErro($resposta);
        }

        $dados = $resposta->json();
        if (isset($dados['error']) && is_array($dados['error'])) {
            throw self::interpretarErro($resposta);
        }

        $metros = $dados['routes'][0]['distanceMeters'] ?? null;
        if (!is_int($metros) || $metros < 0 || $metros > 20000000) {
            throw new IntegracaoErro('O Google não encontrou uma rota utilizável para o endereço informado. Confira o endereço e tente outro destino.');
        }
        return $metros / 1000.0;
    }

    /**
     * Google REST: {"error":{"code":403,"message":"...","status":"PERMISSION_DENIED",
     * "details":[{"@type":"type.googleapis.com/google.rpc.ErrorInfo","reason":"..."}]}}.
     * Exibir SOMENTE diagnostico traduzido e codigo de erro validado; nunca a mensagem crua.
     */
    private static function interpretarErro(RespostaHttp $resposta): IntegracaoErro
    {
        $dados = $resposta->json();
        $erro = is_array($dados['error'] ?? null) ? $dados['error'] : [];

        $status = (string) ($erro['status'] ?? '');
        if (preg_match('/\A[A-Z][A-Z0-9_]{1,63}\z/D', $status) !== 1) {
            $status = '';
        }

        $motivo = '';
        $detalhes = $erro['details'] ?? [];
        if (is_array($detalhes)) {
            foreach ($detalhes as $detalhe) {
                if (!is_array($detalhe) || !is_string($detalhe['@type'] ?? null)
                    || !str_ends_with($detalhe['@type'], '/google.rpc.ErrorInfo')) {
                    continue;
                }
                $candidato = $detalhe['reason'] ?? null;
                if (is_string($candidato) && preg_match('/\A[A-Z][A-Z0-9_]{1,79}\z/D', $candidato) === 1) {
                    $motivo = $candidato;
                    break;
                }
            }
        }

        // Alguns erros antigos retornam apenas "message" e "status", sem ErrorInfo.
        // O texto original serve unicamente para reconhecer sintomas conhecidos, NUNCA e exibido.
        if ($motivo === '' && is_string($erro['message'] ?? null)) {
            $mensagemGoogle = strtolower($erro['message']);
            $motivo = match (true) {
                str_contains($mensagemGoogle, 'billing') && (str_contains($mensagemGoogle, 'not enabled')
                    || str_contains($mensagemGoogle, 'disabled') || str_contains($mensagemGoogle, 'not active')) => 'BILLING_DISABLED',
                str_contains($mensagemGoogle, 'api key') && (str_contains($mensagemGoogle, 'not valid')
                    || str_contains($mensagemGoogle, 'invalid') || str_contains($mensagemGoogle, 'expired')) => 'API_KEY_INVALID',
                str_contains($mensagemGoogle, 'ip address') && (str_contains($mensagemGoogle, 'blocked')
                    || str_contains($mensagemGoogle, 'not allowed') || str_contains($mensagemGoogle, 'restriction')) => 'API_KEY_IP_ADDRESS_BLOCKED',
                (str_contains($mensagemGoogle, 'referrer') || str_contains($mensagemGoogle, 'referer'))
                    && (str_contains($mensagemGoogle, 'blocked') || str_contains($mensagemGoogle, 'restriction')) => 'API_KEY_HTTP_REFERRER_BLOCKED',
                str_contains($mensagemGoogle, 'api') && str_contains($mensagemGoogle, 'not enabled') => 'SERVICE_DISABLED',
                default => '',
            };
        }

        $orientacao = match ($motivo) {
            'API_KEY_IP_ADDRESS_BLOCKED' => 'O Google bloqueou o IP de origem desta requisição. Confira o IP de saída efetivo do servidor PHP (IPv4/IPv6, NAT ou proxy) e a lista de IPs autorizados na chave.',
            'API_KEY_HTTP_REFERRER_BLOCKED' => 'A chave está restrita a sites (referenciadores HTTP). Para chamadas PHP no servidor, configure a restrição por endereço IP.',
            'API_KEY_SERVICE_BLOCKED' => 'Esta chave não tem permissão para chamar a Routes API. Em Credenciais, autorize a Routes API nas restrições de API da chave.',
            'SERVICE_DISABLED' => 'A Routes API está desativada no projeto Google Cloud associado a esta chave. Ative-a em APIs e serviços → Biblioteca.',
            'API_KEY_INVALID' => 'A chave enviada está inválida, revogada ou expirou. Confira a chave cadastrada no ADM Master e, se necessário, substitua-a.',
            'BILLING_DISABLED' => 'O faturamento do projeto Google Cloud está desativado ou não está corretamente vinculado. Confira a conta de faturamento e a forma de pagamento.',
            'RATE_LIMIT_EXCEEDED', 'RESOURCE_QUOTA_EXCEEDED', 'EMULATOR_QUOTA_EXCEEDED' =>
                'A cota do Google foi atingida. Confira os limites da Routes API em Google Cloud → Cotas e aguarde a liberação.',
            'CONSUMER_SUSPENDED', 'GCP_SUSPENDED', 'SECURITY_POLICY_VIOLATED',
            'USER_PROJECT_DENIED', 'IAM_PERMISSION_DENIED' =>
                'O projeto Google Cloud está bloqueado ou não possui permissão para esta operação. Confira o estado do projeto e as políticas de acesso.',
            'CREDENTIALS_MISSING', 'UNAUTHENTICATED' =>
                'A requisição não foi autenticada. Confira se a chave foi cadastrada corretamente no ADM Master.',
            default => self::orientacaoPorStatus($resposta->status, $status),
        };

        $referencia = 'HTTP ' . $resposta->status;
        if ($status !== '') {
            $referencia .= ' · ' . $status;
        }
        if ($motivo !== '') {
            $referencia .= ' · ' . $motivo;
        }
        return new IntegracaoErro($orientacao . ' (Google: ' . $referencia . ').');
    }

    private static function orientacaoPorStatus(int $http, string $status): string
    {
        return match (true) {
            $http === 400 || $status === 'INVALID_ARGUMENT' =>
                'O Google rejeitou os parâmetros da rota. Confira origem, destino e a configuração da requisição Routes API.',
            $http === 401 || $status === 'UNAUTHENTICATED' =>
                'A autenticação da Routes API foi recusada. Confira a chave cadastrada.',
            $http === 403 || $status === 'PERMISSION_DENIED' =>
                'O Google negou permissão para esta requisição. Confira o faturamento, a Routes API ativada e as restrições da chave no projeto. O motivo específico não foi informado pela API.',
            $http === 404 || $status === 'NOT_FOUND' =>
                'O Google não encontrou uma rota para o endereço de teste. Confira a origem e o destino.',
            $http === 429 || $status === 'RESOURCE_EXHAUSTED' =>
                'O limite de consultas do Google foi atingido. Consulte as cotas da Routes API no Google Cloud.',
            $http === 408 || $http === 504 || $status === 'DEADLINE_EXCEEDED' =>
                'O Google demorou demais para responder. Tente novamente em instantes.',
            $http >= 500 || in_array($status, ['INTERNAL', 'UNAVAILABLE', 'UNKNOWN'], true) =>
                'O serviço de rotas do Google está temporariamente indisponível. Tente novamente mais tarde.',
            default => 'O Google não conseguiu calcular a rota. Confira as configurações da Routes API.',
        };
    }
}
