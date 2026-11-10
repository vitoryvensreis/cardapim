<?php

declare(strict_types=1);

namespace Cardapim\Shared\Seguranca;

/**
 * Codigos de recuperacao da verificacao em duas etapas: 10 codigos de uso unico (xxxx-xxxx),
 * mostrados uma vez e guardados apenas como hash SHA-256 (alta entropia: 40 bits cada).
 */
final class CodigosRecuperacao
{
    public const QUANTIDADE = 10;
    private const ALFABETO = 'abcdefghjkmnpqrstuvwxyz23456789'; // sem 0/o, 1/l/i

    /** @return list<string> */
    public static function gerar(): array
    {
        $codigos = [];
        while (count($codigos) < self::QUANTIDADE) {
            $codigo = '';
            for ($i = 0; $i < 8; $i++) {
                $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }
            $codigos[substr($codigo, 0, 4) . '-' . substr($codigo, 4)] = true;
        }
        return array_keys($codigos);
    }

    public static function normalizar(string $codigo): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $codigo) ?? '');
    }

    public static function hash(string $codigo): string
    {
        return hash('sha256', self::normalizar($codigo));
    }

    public static function pareceCodigo(string $texto): bool
    {
        return preg_match('/\A[a-z0-9]{8}\z/', self::normalizar($texto)) === 1;
    }
}
