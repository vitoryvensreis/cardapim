<?php

declare(strict_types=1);

namespace Cardapim\Modules\Entrega;

/** Distancia em linha reta entre dois pontos (formula de haversine). Sem API: conta feita no servidor. */
final class Distancia
{
    private const RAIO_TERRA_KM = 6371.0088;

    public static function km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * self::RAIO_TERRA_KM * asin(min(1.0, sqrt($a)));
    }


    /**
     * Filtro de economia conservador, exclusivo de GPS solicitado pelo cliente no endereço.
     * Nao utiliza coordenadas aproximadas de CEP/bairro, nao aprova taxa e nao substitui a rota.
     * A margem absorve pequenas imprecisoes do GPS e do ponto cadastrado do restaurante.
     */
    public static function foraDoRaioComGpsConfiavel(
        float $origemLat, float $origemLon,
        mixed $destinoLat, mixed $destinoLon, mixed $precisaoMetros,
        float $raioMaximoKm
    ): bool {
        if ($raioMaximoKm <= 0 || !self::coordenadaValida($origemLat, $origemLon)
            || !self::coordenadaValida($destinoLat, $destinoLon)
            || !is_numeric($precisaoMetros) || (float) $precisaoMetros < 1 || (float) $precisaoMetros > 50) {
            return false;
        }
        $distanciaKm = self::km($origemLat, $origemLon, (float) $destinoLat, (float) $destinoLon);
        return $distanciaKm > $raioMaximoKm + 0.5 + (float) $precisaoMetros / 1000;
    }

    public static function coordenadaValida(mixed $lat, mixed $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon)
            && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lon >= -180 && (float) $lon <= 180
            && !((float) $lat == 0.0 && (float) $lon == 0.0);
    }
}
