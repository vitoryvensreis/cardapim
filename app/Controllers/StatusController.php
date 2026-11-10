<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Services\Configuracoes;
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

        $logoPreta = null;
        $iconeUrl = null;
        if ($databaseReady) {
            try {
                $logoPreta = Configuracoes::logo('logo_preta');
                $iconeUrl = Configuracoes::logo('icone');
            } catch (Throwable $exception) {
                // Sem banco/configuracao, a pagina de status continua legivel em texto.
                error_log('[Cardapim] Branding indisponivel no status: ' . get_class($exception));
            }
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
                    'tempo_real' => self::tempoReal(),
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
                'logoPreta' => $logoPreta,
                'iconeUrl' => $iconeUrl,
            ], 'layouts/status'),
            $httpStatus,
            [...$headers, 'Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Situacao do servidor WebSocket pelo arquivo que ele atualiza a cada 10 s (nao afeta o HTTP 200/503).
     * @return array{situacao:string, redis?:string}
     */
    private static function tempoReal(): array
    {
        $arquivo = dirname(__DIR__, 2) . '/storage/cache/tempo-real.json';
        $dados = is_file($arquivo) ? json_decode((string) @file_get_contents($arquivo), true) : null;
        if (!is_array($dados) || !is_int($dados['atualizado_em'] ?? null)) {
            return ['situacao' => 'nao_iniciado'];
        }
        if (($dados['situacao'] ?? '') !== 'operacional' || time() - $dados['atualizado_em'] > 30) {
            return ['situacao' => 'parado'];
        }
        return ['situacao' => 'operacional', 'redis' => in_array($dados['redis'] ?? '', ['conectado', 'indisponivel', 'desligado'], true) ? $dados['redis'] : 'desconhecido'];
    }
}
