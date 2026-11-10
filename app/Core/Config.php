<?php

declare(strict_types=1);

namespace Cardapim\Core;

/**
 * Configuracao do ambiente: variavel de ambiente real (getenv) ou, se nao existir,
 * o arquivo .env da raiz (shared/.env na VPS). Segredos nunca vao para o Git.
 */
final class Config
{
    /** @var array<string,string>|null */
    private static ?array $arquivo = null;

    public static function get(string $chave, string $padrao = ''): string
    {
        $valor = getenv($chave);
        if (is_string($valor) && $valor !== '') {
            return $valor;
        }
        $arquivo = self::arquivo();
        return isset($arquivo[$chave]) && $arquivo[$chave] !== '' ? $arquivo[$chave] : $padrao;
    }

    /** Para testes. */
    public static function limparCache(): void
    {
        self::$arquivo = null;
    }

    /** @return array<string,string> */
    private static function arquivo(): array
    {
        if (self::$arquivo !== null) {
            return self::$arquivo;
        }
        $caminho = dirname(__DIR__, 2) . '/.env';
        $dados = is_file($caminho) && is_readable($caminho) ? @parse_ini_file($caminho, false, INI_SCANNER_RAW) : [];
        self::$arquivo = [];
        foreach (is_array($dados) ? $dados : [] as $chave => $valor) {
            if (is_string($chave) && is_scalar($valor)) {
                self::$arquivo[$chave] = trim((string) $valor);
            }
        }
        return self::$arquivo;
    }
}
