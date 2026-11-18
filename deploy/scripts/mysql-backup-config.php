<?php

declare(strict_types=1);

// Executado somente pelo script de deploy na VPS (PHP CLI).
// Gera um my.cnf temporario para mysqldump; nunca imprime senha nos logs.
// A saida deve ser redirecionada diretamente para arquivo 600 em shared/backups/.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Config;

$host = Config::get('DB_HOST', '127.0.0.1');
$port = Config::get('DB_PORT', '3306');
$banco = Config::get('DB_DATABASE');
$usuario = Config::get('DB_USERNAME');
$senha = Config::get('DB_PASSWORD');

if ($banco !== 'cardapim' || $usuario === '' || $senha === ''
    || preg_match('/\A[A-Za-z0-9.:-]+\z/', $host) !== 1
    || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    fwrite(STDERR, "Configuracao privada de backup MySQL incompleta ou invalida.\n");
    exit(1);
}

foreach ([$usuario, $senha, $host] as $valor) {
    if (preg_match('/[\x00-\x1f\x7f]/', $valor) === 1) {
        fwrite(STDERR, "Valor invalido na configuracao de backup MySQL.\n");
        exit(1);
    }
}
$esc = static fn (string $valor): string => '"' . addcslashes($valor, "\\\"") . '"';
echo "[client]\n";
echo 'host=' . $esc($host) . "\n";
echo 'port=' . (int) $port . "\n";
echo 'user=' . $esc($usuario) . "\n";
echo 'password=' . $esc($senha) . "\n";
echo "default-character-set=utf8mb4\n";
