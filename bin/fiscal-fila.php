<?php

declare(strict_types=1);

// Fila de emissão de NFC-e. Cron (usuario do site): * * * * * php8.4 .../current/bin/fiscal-fila.php
// Processa notas pendentes, incertas (confirma a situação antes de reenviar) e com pendência de cadastro.
// Uma rodada por vez (flock); cada nota é reivindicada antes do envio, então não há envio duplicado.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Services\Fiscal\EmissorFiscal;
use Cardapim\Shared\Esquema;

$trava = fopen(sys_get_temp_dir() . '/cardapim-fiscal-fila-' . md5(dirname(__DIR__)) . '.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$inicio = time();
$contagem = [];
try {
    $db = Database::connect();
    if (!Esquema::tem($db, 'fiscal_documentos')) {
        exit(0);
    }
    $emissor = new EmissorFiscal($db);
    // "enviando" parado há mais de 5 minutos = rodada interrompida: confirma a situação como incerta.
    $db->exec("UPDATE fiscal_documentos SET status = 'incerta', proxima_tentativa_em = NOW() WHERE status = 'enviando' AND atualizado_em < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $q = $db->query("SELECT id FROM fiscal_documentos
        WHERE status IN ('pendente', 'incerta', 'bloqueada') AND proxima_tentativa_em IS NOT NULL AND proxima_tentativa_em <= NOW()
        ORDER BY proxima_tentativa_em ASC, id ASC LIMIT 40");
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (time() - $inicio > 50) {
            break; // a próxima rodada do cron continua
        }
        $status = $emissor->processar((int) $id);
        $contagem[$status] = ($contagem[$status] ?? 0) + 1;
    }
} catch (Throwable $e) {
    error_log('[Cardapim] Fila fiscal: ' . get_class($e));
    exit(1);
} finally {
    flock($trava, LOCK_UN);
}
if ($contagem !== []) {
    echo date('Y-m-d H:i:s') . ' ' . json_encode($contagem) . "\n";
}
