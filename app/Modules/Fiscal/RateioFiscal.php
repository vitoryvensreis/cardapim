<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

/** Rateio e aritmética exata em centavos (sem float) para os valores da NFC-e. */
final class RateioFiscal
{
    /**
     * Reparte $total centavos proporcionalmente aos pesos (maiores restos). A soma devolvida é sempre $total.
     * @param array<int|string,int> $pesos centavos de cada item
     * @return array<int|string,int>
     */
    public static function repartir(int $total, array $pesos): array
    {
        $out = array_fill_keys(array_keys($pesos), 0);
        $soma = array_sum($pesos);
        if ($total <= 0 || $soma <= 0) { return $out; }
        $restos = [];
        $distribuido = 0;
        foreach ($pesos as $k => $p) {
            $parte = intdiv($total * $p, $soma);
            $out[$k] = $parte;
            $distribuido += $parte;
            $restos[$k] = ($total * $p) % $soma;
        }
        arsort($restos);
        foreach (array_keys($restos) as $k) {
            if ($distribuido >= $total) { break; }
            $out[$k]++;
            $distribuido++;
        }
        return $out;
    }

    /** Centavos de um decimal textual "12.34" ou "12,34". */
    public static function centavos(string $v): int
    {
        $v = str_replace(',', '.', trim($v));
        if (!preg_match('/\A(-?)(\d+)(?:\.(\d{1,2}))?\d*\z/', $v, $m)) { return 0; }
        $c = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$c : $c;
    }

    /** Milésimos de uma quantidade "1.250" (até 3 casas). */
    public static function milesimos(string $v): int
    {
        $v = str_replace(',', '.', trim($v));
        if (!preg_match('/\A(\d+)(?:\.(\d{1,3}))?\d*\z/', $v, $m)) { return 0; }
        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '0', 3, '0');
    }

    /** Valor em formato do INI (vírgula, 2 casas). */
    public static function dinheiro(int $centavos): string
    {
        $neg = $centavos < 0 ? '-' : '';
        $c = abs($centavos);
        return $neg . intdiv($c, 100) . ',' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Quantidade em milésimos para o INI (até 4 casas, sem zeros à direita desnecessários). */
    public static function quantidade(int $milesimos): string
    {
        $s = intdiv($milesimos, 1000) . ',' . str_pad((string) ($milesimos % 1000), 3, '0', STR_PAD_LEFT) . '0';
        return rtrim(rtrim($s, '0'), ',') ?: '0';
    }

    /** Valor unitário = centavos / quantidade, com até 8 casas e divisão inteira exata. */
    public static function unitario(int $centavos, int $milesimos): string
    {
        if ($milesimos <= 0) { return '0'; }
        $escala = 100000000; // 8 casas
        $n = intdiv($centavos * 1000 * ($escala / 100), $milesimos);
        $s = intdiv($n, $escala) . ',' . str_pad((string) ($n % $escala), 8, '0', STR_PAD_LEFT);
        return rtrim(rtrim($s, '0'), ',');
    }

    /** Percentual textual ("18" ou "7.5") aplicado a centavos, arredondado ao centavo. */
    public static function percentual(int $base, string $aliquota): int
    {
        $a = str_replace(',', '.', trim($aliquota));
        if (!preg_match('/\A(\d{1,3})(?:\.(\d{1,4}))?\z/', $a, $m)) { return 0; }
        $milionesimos = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '0', 4, '0'); // pontos-base * 100
        return intdiv($base * $milionesimos + 500000, 1000000);
    }

    public static function aliquota(string $aliquota): string
    {
        $a = str_replace('.', ',', trim($aliquota));
        return $a === '' ? '0,00' : $a;
    }
}
