<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use InvalidArgumentException;

/** Chave de acesso de 44 dígitos (leiaute NF-e/NFC-e 4.00), com dígito verificador módulo 11. */
final class ChaveAcesso
{
    public static function gerar(int $cUF, \DateTimeInterface $emissao, string $cnpj, int $modelo, int $serie, int $numero, int $tpEmis, string $cNF): string
    {
        if (!preg_match('/\A\d{14}\z/', $cnpj) || !preg_match('/\A\d{8}\z/', $cNF) || $serie < 0 || $serie > 999 || $numero < 1 || $numero > 999999999) {
            throw new InvalidArgumentException('Dados inválidos para a chave de acesso.');
        }
        $base = sprintf('%02d%s%s%02d%03d%09d%d%s', $cUF, $emissao->format('ym'), $cnpj, $modelo, $serie, $numero, $tpEmis, $cNF);
        return $base . self::dv($base);
    }

    public static function dv(string $base43): int
    {
        $soma = 0; $peso = 2;
        for ($i = strlen($base43) - 1; $i >= 0; $i--) {
            $soma += (int) $base43[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    }

    public static function valida(string $chave): bool
    {
        return preg_match('/\A\d{44}\z/', $chave) === 1 && self::dv(substr($chave, 0, 43)) === (int) $chave[43];
    }

    /** cNF aleatório de 8 dígitos, diferente do número da nota (regra da SEFAZ). */
    public static function codigoAleatorio(int $numero): string
    {
        do { $c = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT); } while ((int) $c === $numero);
        return $c;
    }
}
