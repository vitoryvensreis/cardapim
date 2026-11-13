<?php

declare(strict_types=1);

namespace Cardapim\Core;

use InvalidArgumentException;
use RuntimeException;

final class AssetUrl
{
    /** @var array<string,string> versao (data do arquivo) ja calculada nesta requisicao */
    private static array $versoes = [];

    /**
     * CSS/JS/imagens internas sao relativos ao dominio atual, sem consulta ao banco.
     * Leva ?v=<data do arquivo>: cada deploy muda a URL, entao celular, navegador e cache do servidor
     * (Varnish) pegam o arquivo novo na hora, mesmo guardando os estaticos por muito tempo.
     */
    public static function asset(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, '..')
            || preg_match('~\A[a-zA-Z0-9_./-]+\z~D', $relativePath) !== 1
            || str_starts_with($relativePath, '/')) {
            throw new InvalidArgumentException('Caminho de asset invalido.');
        }

        $versao = self::$versoes[$relativePath] ??= self::versao($relativePath);
        return '/assets/' . $relativePath . ($versao !== '' ? '?v=' . $versao : '');
    }

    private static function versao(string $relativePath): string
    {
        $arquivo = dirname(__DIR__, 2) . '/public/assets/' . $relativePath;
        $data = is_file($arquivo) ? @filemtime($arquivo) : false;
        return $data === false ? '' : base_convert((string) $data, 10, 36);
    }

    /**
     * Para links externos que precisam de URL completa: APP_URL continua no .env.
     */
    public static function absolute(string $relativePath): string
    {
        return self::base() . self::asset($relativePath);
    }

    /**
     * Endereco do site sem barra no fim (APP_URL do .env), ex.: "https://exemplo.com.br".
     * @throws RuntimeException se APP_URL estiver ausente ou invalida
     */
    public static function base(): string
    {
        $url = getenv('APP_URL');
        if ($url === false || $url === '') {
            $file = dirname(__DIR__, 2) . '/.env';
            $env = is_file($file) ? parse_ini_file($file, false, INI_SCANNER_RAW) : [];
            $url = is_array($env) ? (string) ($env['APP_URL'] ?? '') : '';
        }

        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
            || parse_url($url, PHP_URL_PATH) !== null && parse_url($url, PHP_URL_PATH) !== '/') {
            throw new RuntimeException('APP_URL invalida ou nao configurada.');
        }

        return rtrim($url, '/');
    }
}
