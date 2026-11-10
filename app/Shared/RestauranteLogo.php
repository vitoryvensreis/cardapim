<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/** Valida a logo de um restaurante antes de exibir; caso contrario usa iniciais. */
final class RestauranteLogo
{
    public static function urlSegura(?string $path): ?string
    {
        if ($path === null || $path === '' || preg_match('/[\x00-\x1f\x7f"\'<>\s]/', $path) === 1) {
            return null;
        }
        $local = preg_match('~\A/(?:assets|storage|uploads)/[a-zA-Z0-9._/-]+\.(?:svg|png|jpe?g|webp)\z~i', $path) === 1
            && !str_contains($path, '..');
        $remota = str_starts_with($path, 'https://') && filter_var($path, FILTER_VALIDATE_URL) !== false;
        return $local || $remota ? $path : null;
    }

    public static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        $ignorar = ['de', 'da', 'do', 'das', 'dos', 'e'];
        $letras = '';
        foreach ($partes as $parte) {
            if ($parte === '' || in_array(mb_strtolower($parte), $ignorar, true)) {
                continue;
            }
            $letras .= mb_strtoupper(mb_substr($parte, 0, 1));
            if (mb_strlen($letras) === 2) {
                break;
            }
        }
        return $letras !== '' ? $letras : 'R';
    }
}
