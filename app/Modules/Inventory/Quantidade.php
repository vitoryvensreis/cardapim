<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

use InvalidArgumentException;

/**
 * Quantidades de estoque (DECIMAL(..,3)) em milesimos inteiros: conta exata, sem erro de float.
 * 1 kg = 1000; 0,080 kg = 80; 1 un = 1000; 0,5 L = 500.
 */
final class Quantidade
{
    private const ESCALA = 1000;

    /** Aceita "0.080", "0,080", "1", 1.5 (int/float apenas de codigo confiavel). */
    public static function paraMilesimos(string|int|float $valor): int
    {
        if (is_int($valor)) {
            return $valor * self::ESCALA;
        }
        $texto = is_float($valor) ? number_format($valor, 3, '.', '') : str_replace(',', '.', trim($valor));
        if (preg_match('/\A(-?)(\d{1,11})(?:\.(\d{1,3}))?\z/', $texto, $m) !== 1) {
            throw new InvalidArgumentException('Quantidade invalida: use ate 3 casas decimais.');
        }
        $milesimos = (int) $m[2] * self::ESCALA + (int) str_pad($m[3] ?? '', 3, '0');
        return $m[1] === '-' ? -$milesimos : $milesimos;
    }

    /** Formato para o banco: "0.080". */
    public static function paraDecimal(int $milesimos): string
    {
        $sinal = $milesimos < 0 ? '-' : '';
        $abs = abs($milesimos);
        return sprintf('%s%d.%03d', $sinal, intdiv($abs, self::ESCALA), $abs % self::ESCALA);
    }

    /** Quantas vezes "necessario" cabe em "disponivel" (porcoes possiveis). */
    public static function quantasVezes(int $disponivel, int $necessario): int
    {
        if ($necessario <= 0) {
            throw new InvalidArgumentException('Quantidade necessaria deve ser positiva.');
        }
        return $disponivel <= 0 ? 0 : intdiv($disponivel, $necessario);
    }
}
