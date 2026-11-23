<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Shared\Http\ClienteHttp;
use RuntimeException;

/**
 * Routes API v2: somente distancia pelas ruas, sem mapa nem polyline.
 * https://developers.google.com/maps/documentation/routes/compute_route_directions
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
            throw new RuntimeException('Endereco de origem ou destino invalido.');
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

        if ($resposta->status === 429) {
            throw new RuntimeException('Cota de consultas do Google Maps atingida.');
        }
        if (in_array($resposta->status, [401, 403], true)) {
            throw new RuntimeException('Google Maps recusou a chave ou as permissoes da Routes API.');
        }
        if (!$resposta->ok()) {
            throw new RuntimeException('Google Maps nao conseguiu calcular a rota.');
        }

        $dados = $resposta->json();
        $metros = $dados['routes'][0]['distanceMeters'] ?? null;
        if (!is_int($metros) || $metros < 0 || $metros > 20000000) {
            throw new RuntimeException('Google Maps nao retornou uma distancia valida para esse endereco.');
        }
        return $metros / 1000.0;
    }
}
