<?php

declare(strict_types=1);

namespace Cardapim\Modules\Entrega;

/**
 * Compara nomes de bairro/cidade como uma pessoa compararia: sem acento, sem maiusculas, sem espacos
 * repetidos e sem pontuacao. "Aldeota", "ALDEOTA " e "aldeóta" viram "aldeota".
 */
final class TextoLocal
{
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    public static function chave(?string $texto): string
    {
        $t = mb_strtolower(trim((string) $texto), 'UTF-8');
        $t = strtr($t, self::ACENTOS);
        $t = (string) preg_replace('/[^a-z0-9]+/', ' ', $t);
        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    /** Nome limpo para exibir (espacos simples), mantendo acentos e maiusculas digitados. */
    public static function limpo(?string $texto, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)), 0, $max);
    }
}
