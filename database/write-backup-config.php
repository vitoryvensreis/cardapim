<?php

declare(strict_types=1);

// Gerar apenas um arquivo temporario para mysqldump, sem publicar credenciais.
// Chamado pelo script de deploy sob usuario nao-root, a partir de shared/.env.
if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Uso interno: php8.4 database/write-backup-config.php /caminho/temporario.cnf\n");
    exit(2);
}

$target = $argv[1];
$dir = realpath(dirname($target));
$root = dirname(__DIR__);
$shared = realpath($root . '/../../shared/backups');

if ($dir === false || $shared === false || $dir !== $shared || !is_file($target) || is_link($target)) {
    fwrite(STDERR, "Caminho do arquivo temporario invalido.\n");
    exit(1);
}

$envFile = $root . '/.env';
$settings = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : false;
if (!is_array($settings)) {
    fwrite(STDERR, "Configuracao privada indisponivel.\n");
    exit(1);
}

$value = static function (string $key, string $default = '') use ($settings): string {
    $fromEnv = getenv($key);
    return $fromEnv !== false ? $fromEnv : (string) ($settings[$key] ?? $default);
};

$host = $value('DB_HOST', '127.0.0.1');
$port = $value('DB_PORT', '3306');
$user = $value('DB_USERNAME');
$password = $value('DB_PASSWORD');
$name = $value('DB_DATABASE');

if ($name !== 'cardapim' || $host === '' || $user === '' || $password === ''
    || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    fwrite(STDERR, "Configuracao do banco de producao invalida.\n");
    exit(1);
}

$escape = static function (string $text): string {
    if (preg_match('/[\r\n\x00]/', $text)) {
        throw new RuntimeException('Caracter invalido no valor da configuracao.');
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
};

try {
    $cnf = "[client]\n"
        . 'host=' . $escape($host) . "\n"
        . 'port=' . $port . "\n"
        . 'user=' . $escape($user) . "\n"
        . 'password=' . $escape($password) . "\n";

    if (file_put_contents($target, $cnf, LOCK_EX) === false || !chmod($target, 0600)) {
        throw new RuntimeException('Nao foi possivel preparar arquivo privado.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Falha ao preparar credenciais temporarias do backup.\n");
    exit(1);
}
