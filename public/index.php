<?php

declare(strict_types=1);

use Cardapim\Core\DominioCanonico;
use Cardapim\Core\Request;
use Cardapim\Core\Response;
use Cardapim\Core\View;

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    // Endereco antigo (troca de dominio): 301 para o APP_URL antes de qualquer outra coisa.
    $antigo = DominioCanonico::daRequisicao();
    if ($antigo !== null) {
        $antigo->send();
        exit;
    }
    $router = require dirname(__DIR__) . '/routes/web.php';
    $router->dispatch(Request::capture())->send();
} catch (Throwable $exception) {
    error_log('[Cardapim] ' . get_class($exception) . ': ' . $exception->getMessage());
    try {
        $html = View::render('errors/500', [
            'pageTitle' => 'Erro temporário | Cardapim',
            'pageDescription' => 'Erro temporário no Cardapim.',
            'noIndex' => true,
        ], 'layouts/public');
    } catch (Throwable) {
        // Ultimo recurso: HTML minimo, sem estilos inline.
        $html = '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Cardapim</title>'
            . '<h1>Ops! Algo nao saiu como esperado.</h1><p>Tente novamente em instantes.</p><a href="/">Voltar</a></html>';
    }
    Response::html($html, 500)->send();
}
