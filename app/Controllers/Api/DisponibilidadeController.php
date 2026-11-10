<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Repositories\UsuarioRepository;
use Cardapim\Services\Disponibilidade;
use Throwable;

/** GET /api/v1/cadastro/disponibilidade?campo=usuario|email|cnpj&valor=... */
final class DisponibilidadeController
{
    /** Limite por IP para dificultar varredura de e-mails cadastrados. */
    private const MAX_POR_JANELA = 60;
    private const JANELA_SEGUNDOS = 300;

    public function verificar(): Response
    {
        $campo = is_string($_GET['campo'] ?? null) ? $_GET['campo'] : '';
        $valor = is_string($_GET['valor'] ?? null) ? $_GET['valor'] : '';
        if (!in_array($campo, Disponibilidade::CAMPOS, true) || $valor === '' || strlen($valor) > 200) {
            return Response::json(['erro' => 'Parâmetros inválidos.'], 422);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido');
        if (!RateLimiter::padrao(self::MAX_POR_JANELA, self::JANELA_SEGUNDOS)->permitir('disponibilidade|' . $ip)) {
            return Response::json(
                ['erro' => 'Muitas verificações em pouco tempo. Aguarde alguns minutos.'],
                429,
                ['Retry-After' => (string) self::JANELA_SEGUNDOS],
            );
        }

        try {
            $db = Database::connect();
            $servico = new Disponibilidade(new UsuarioRepository($db), new RestauranteRepository($db));
            return Response::json($servico->verificar($campo, $valor));
        } catch (Throwable $exception) {
            error_log('[Cardapim] Disponibilidade indisponivel: ' . get_class($exception));
            return Response::json(['erro' => 'Não foi possível verificar agora.'], 503);
        }
    }
}
