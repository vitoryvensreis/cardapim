<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $linhas = \Cardapim\Services\Pedidos\SaudacaoWhatsApp::limparAntigos(\Cardapim\Core\Database::connect());
    echo "Saudacoes: " . $linhas . " registros antigos eliminados.\n";
} catch (Throwable $erro) {
    error_log('[Cardapim] Limpeza de saudacoes: ' . get_class($erro));
    exit(1);
}
