<?php

declare(strict_types=1);

// Vigia do WhatsApp (WAHA): reinicia sessao que caiu, avisa a loja quando precisa de alguem no celular e tenta de
// novo as boas-vindas que falharam. Instalado no crontab pelo deploy (deploy/scripts/install-whatsapp-vigia-cron.sh).
//
// Uso (usuario do site, na pasta current/):
//   php8.4 bin/whatsapp-vigia.php
// Cron (a cada minuto):
//   * * * * * php8.4 /home/<usuario>/apps/cardapim/current/bin/whatsapp-vigia.php >> /home/<usuario>/apps/cardapim/shared/storage/logs/whatsapp-vigia.log 2>&1
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Services\WhatsApp\VigiaWhatsApp;

$pasta = dirname(__DIR__) . '/storage/cache';
if (!is_dir($pasta)) {
    @mkdir($pasta, 0775, true);
}
$trava = fopen($pasta . '/whatsapp-vigia.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0); // rodada anterior ainda em andamento
}

try {
    $r = (new VigiaWhatsApp(Database::connect()))->rodar();
} catch (Throwable $erro) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ERRO: ' . get_class($erro) . "\n");
    exit(1);
}
// Log so quando algo aconteceu (rodada a cada minuto).
if ($r['reiniciados'] + $r['alertas'] + $r['recuperados'] + $r['boas_vindas'] > 0) {
    printf("%s verificados=%d reiniciados=%d alertas=%d recuperados=%d boas_vindas=%d\n",
        date('Y-m-d H:i:s'), $r['verificados'], $r['reiniciados'], $r['alertas'], $r['recuperados'], $r['boas_vindas']);
}
