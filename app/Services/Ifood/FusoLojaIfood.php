<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use DateTimeZone;

/**
 * Fuso da loja Merchant: nunca usar o fuso do servidor para interpretar
 * datetime-local de uma unidade em outra região.
 * O iFood não expõe um campo de timezone no GET /merchants/{id}; a sugestão
 * considera o endereço cadastrado. O operador pode corrigir a sugestão.
 */
final class FusoLojaIfood
{
    /** @var array<string,string> */
    public const OPCOES = [
        'America/Rio_Branco' => 'Acre (UTC−05:00)',
        'America/Manaus' => 'Amazonas / Mato Grosso (UTC−04:00)',
        'America/Sao_Paulo' => 'Brasília / Fortaleza (UTC−03:00)',
        'America/Noronha' => 'Fernando de Noronha (UTC−02:00)',
    ];

    /** @param array<string,mixed> $detalhes */
    public static function sugerir(array $detalhes): string
    {
        $endereco = is_array($detalhes['address'] ?? null) ? $detalhes['address'] : [];
        $estado = strtoupper(trim((string) ($endereco['state'] ?? '')));
        $cidade = self::normalizar((string) ($endereco['city'] ?? ''));
        if ($cidade === 'fernando de noronha') {
            return 'America/Noronha';
        }
        if ($estado === 'AC' || ($estado === 'AM' && in_array($cidade, ['eirunepe', 'benjamin constant', 'tabatinga'], true))) {
            return 'America/Rio_Branco';
        }
        if (in_array($estado, ['AM', 'MT', 'MS', 'RO', 'RR'], true)) {
            return 'America/Manaus';
        }
        return 'America/Sao_Paulo';
    }

    public static function valido(string $fuso): bool
    {
        return isset(self::OPCOES[$fuso]);
    }

    public static function timezone(string $fuso): DateTimeZone
    {
        return new DateTimeZone(self::valido($fuso) ? $fuso : 'America/Sao_Paulo');
    }

    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        return strtr($texto, ['á' => 'a', 'â' => 'a', 'ã' => 'a', 'à' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }
}
