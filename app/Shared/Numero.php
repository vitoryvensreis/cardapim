<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/**
 * Conversao entre o que a pessoa digita (formato brasileiro) e o formato do banco (DECIMAL com ponto).
 * Valores monetarios sempre como string decimal, nunca float.
 */
final class Numero
{
    /** "1.234,56" | "24,9" | "24.90" | "24" -> "1234.56" ; invalido -> null */
    public static function dinheiroParaDecimal(string $texto): ?string
    {
        $texto = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $texto));
        if ($texto === '') {
            return null;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        } elseif (preg_match('/\A\d{1,3}(\.\d{3})+\z/', $texto) === 1) {
            $texto = str_replace('.', '', $texto); // "1.234" = mil duzentos e trinta e quatro
        }
        if (preg_match('/\A(\d{1,8})(?:\.(\d{1,2}))?\z/', $texto, $m) !== 1) {
            return null;
        }
        return sprintf('%d.%s', (int) $m[1], str_pad($m[2] ?? '', 2, '0'));
    }

    /** Custo com ate 4 casas (insumos): "0,0125" -> "0.0125". */
    public static function custoParaDecimal(string $texto): ?string
    {
        $texto = trim(str_replace(['R$', ' '], '', $texto));
        if ($texto === '') {
            return null;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }
        if (preg_match('/\A(\d{1,10})(?:\.(\d{1,4}))?\z/', $texto, $m) !== 1) {
            return null;
        }
        return sprintf('%d.%s', (int) $m[1], str_pad($m[2] ?? '', 4, '0'));
    }

    /** "24.90" -> "R$ 24,90" */
    public static function reais(string|int|float|null $decimal): string
    {
        return 'R$ ' . self::dinheiroCampo($decimal);
    }

    /** "1234.5" -> "1.234,50" (para preencher campos com mascara de dinheiro) */
    public static function dinheiroCampo(string|int|float|null $decimal): string
    {
        if ($decimal === null || $decimal === '') {
            return '';
        }
        $centavos = self::centavos((string) $decimal);
        $sinal = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);
        return $sinal . number_format(intdiv($centavos, 100), 0, ',', '.') . ',' . sprintf('%02d', $centavos % 100);
    }

    /** "24.905" -> 2491 (arredonda meio para cima), sem passar por float. */
    public static function centavos(string $decimal): int
    {
        if (preg_match('/\A(-?)(\d+)(?:\.(\d+))?\z/', trim($decimal), $m) !== 1) {
            return 0;
        }
        $fracao = str_pad($m[3] ?? '', 3, '0');
        $valor = (int) $m[2] * 100 + (int) substr($fracao, 0, 2) + ((int) $fracao[2] >= 5 ? 1 : 0);
        return $m[1] === '-' ? -$valor : $valor;
    }

    /** Decimal do banco para campo de texto sem zeros sobrando: "0.0125" -> "0,0125"; "2.5000" -> "2,5" */
    public static function decimalCampo(string|int|float|null $decimal): string
    {
        if ($decimal === null || $decimal === '') {
            return '';
        }
        $texto = (string) $decimal;
        if (str_contains($texto, '.')) {
            $texto = rtrim(rtrim($texto, '0'), '.');
        }
        return str_replace('.', ',', $texto);
    }
}
