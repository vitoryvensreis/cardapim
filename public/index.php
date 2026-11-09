<?php

declare(strict_types=1);

use Cardapim\Core\Request;
use Cardapim\Core\Response;

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $router = require dirname(__DIR__) . '/routes/web.php';
    $router->dispatch(Request::capture())->send();
} catch (Throwable $exception) {
    error_log('[Cardapim] ' . $exception->getMessage());
    Response::html(
        '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Cardapim</title><body style="font:16px system-ui;padding:3rem"><h1>Ops! Algo nao saiu como esperado.</h1><p>Tente novamente em instantes.</p><a href="/">Voltar</a></body></html>',
        500
    )->send();
}
