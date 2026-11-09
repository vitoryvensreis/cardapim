<?php

declare(strict_types=1);

namespace Cardapim\Core;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(): PDO
    {
        $file = dirname(__DIR__, 2) . '/.env';
        $environment = is_file($file) ? parse_ini_file($file, false, INI_SCANNER_RAW) : [];
        if (!is_array($environment)) {
            throw new RuntimeException('Arquivo de configuracao do banco invalido.');
        }

        $get = static function (string $key, string $default = '') use ($environment): string {
            $value = getenv($key);
            return $value === false ? (string) ($environment[$key] ?? $default) : $value;
        };

        $host = $get('DB_HOST', '127.0.0.1');
        $port = $get('DB_PORT', '3306');
        $name = $get('DB_DATABASE');
        $user = $get('DB_USERNAME');
        $password = $get('DB_PASSWORD');

        if ($host === '' || $name === '' || $user === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Configuracao de banco incompleta.');
        }

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }
}
