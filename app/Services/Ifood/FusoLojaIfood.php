<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use DateTimeZone;

/**
 * Fuso operacional fixo de Brasilia para as pausas do iFood.
 * Mantem os metodos de compatibilidade usados pelos clientes da API:
 * nenhum endereco ou configuracao persistida pode alterar este fuso.
 */
final class FusoLojaIfood
{
    public const PADRAO = 'America/Sao_Paulo';

    /** @param array<string,mixed> $detalhes */
    public static function sugerir(array $detalhes): string
    {
        return self::PADRAO;
    }

    public static function valido(string $fuso): bool
    {
        return $fuso === self::PADRAO;
    }

    public static function timezone(string $fuso = self::PADRAO): DateTimeZone
    {
        return new DateTimeZone(self::PADRAO);
    }
}
