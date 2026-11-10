<?php

declare(strict_types=1);

/**
 * Servidor de tempo real (WebSocket) do Cardapim.
 * Na VPS roda pelo systemd (deploy/systemd/cardapim-tempo-real.service), como o usuario do site.
 * Localmente: php bin/tempo-real.php
 *
 * Configuracao (.env): WS_PORTA (padrao 8091), WS_ORIGENS (extras, separadas por virgula),
 * REDIS_HOST/REDIS_PORT/REDIS_PASSWORD (REDIS_PORT vazio = sem Redis), APP_URL (origem permitida).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Realtime\RedisConexao;
use Cardapim\Realtime\Servidor;

$log = static function (string $mensagem): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL);
};

$porta = Config::get('WS_PORTA', '8091');
if (!ctype_digit($porta) || (int) $porta < 1024 || (int) $porta > 65535) {
    $log('WS_PORTA invalida.');
    exit(2);
}

$origens = [];
foreach ([Config::get('APP_URL'), ...explode(',', Config::get('WS_ORIGENS'))] as $url) {
    $partes = parse_url(trim($url));
    if (is_array($partes) && in_array($partes['scheme'] ?? '', ['http', 'https'], true) && isset($partes['host'])) {
        $origens[] = $partes['scheme'] . '://' . $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }
}
if ($origens === []) {
    $log('Configure APP_URL para saber de qual site aceitar conexoes.');
    exit(2);
}

// Versao em uso: a pasta da release. Se o link "current" mudar (deploy novo), o servidor reinicia sozinho.
$release = dirname(__DIR__);
$atual = dirname($release, 2) . '/current';
$checarVersao = is_link($atual) ? static fn (): bool => realpath($atual) === $release : null;

$servidor = new Servidor([
    'host' => '127.0.0.1',
    'porta' => (int) $porta,
    'origens' => array_values(array_unique($origens)),
    'max_conexoes' => 900, // stream_select aceita ate 1024 sockets por processo
    'max_por_usuario' => 10,
    'max_por_ip' => 60,
    'arquivo_status' => is_dir($release . '/storage/cache') ? $release . '/storage/cache/tempo-real.json' : null,
    'versao' => substr(basename($release), 0, 12),
    'checar_versao' => $checarVersao,
    'redis' => RedisConexao::configurado(),
], Closure::fromCallable([Database::class, 'novaConexao']), $log);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static fn () => $servidor->parar());
    pcntl_signal(SIGINT, static fn () => $servidor->parar());
}

exit($servidor->rodar());
