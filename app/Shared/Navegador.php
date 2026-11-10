<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/** Resumo legivel do navegador para logs e "aparelhos conectados" (sem guardar o User-Agent inteiro). */
final class Navegador
{
    public static function resumo(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return 'Navegador desconhecido';
        }
        $navegador = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            default => 'Outro navegador',
        };
        $sistema = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'sistema desconhecido',
        };
        return $navegador . ' no ' . $sistema;
    }
}
