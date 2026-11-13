<?php

declare(strict_types=1);

namespace Cardapim\Services\Descontos;

use Cardapim\Shared\Numero;

/** Contas e textos comuns de desconto (cupom e fidelidade): percentual ou valor fixo, sempre em centavos. */
final class Desconto
{
    public const TIPOS = ['percentual' => 'Porcentagem (%)', 'fixo' => 'Valor fixo (R$)'];

    /** Desconto em centavos sobre o valor dos itens (nunca passa dos itens). Percentual arredonda meio centavo para cima. */
    public static function calcular(string $tipo, string $valor, int $subtotal): int
    {
        if ($subtotal <= 0) {
            return 0;
        }
        $v = Numero::centavos($valor); // 10.00 -> 1000 (10% ou R$ 10,00)
        $d = $tipo === 'percentual' ? intdiv($subtotal * $v + 5000, 10000) : $v;
        return max(0, min($d, $subtotal));
    }

    /** "10%" ou "R$ 5,00". */
    public static function rotulo(string $tipo, string $valor): string
    {
        if ($tipo === 'percentual') {
            return rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',') . '%';
        }
        return Numero::reais($valor);
    }

    public static function dec(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }
}
