<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Throwable;

final class StatusController
{
    public function index(): Response
    {
        $databaseReady = false;

        try {
            $databaseReady = (int) Database::connect()->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable $exception) {
            // A rota publica nao revela host, usuario, senha, query ou detalhes do PDO.
            error_log('[Cardapim] Verificacao de MySQL indisponivel: ' . get_class($exception));
        }

        $httpStatus = $databaseReady ? 200 : 503;
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $overallStatus = $databaseReady ? 'operacional' : 'indisponivel';

        $headers = [
            'Cache-Control' => 'no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            $body = json_encode([
                'status' => $overallStatus,
                'servicos' => [
                    'php' => ['situacao' => 'operacional', 'versao' => $phpVersion],
                    'mysql' => ['situacao' => $databaseReady ? 'conectado' : 'indisponivel'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return new Response($body, $httpStatus, [
                ...$headers,
                'Content-Type' => 'application/json; charset=UTF-8',
            ]);
        }

        return new Response(
            View::render('status/index', [
                'databaseReady' => $databaseReady,
                'phpVersion' => $phpVersion,
                'checkedAt' => date('d/m/Y H:i:s'),
            ], 'layouts/status'),
            $httpStatus,
            [...$headers, 'Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
