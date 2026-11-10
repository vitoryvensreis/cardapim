<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Services\Catalogo;
use Throwable;

/** GET /api/v1/restaurantes?cursor=...&busca=... (somente dados publicos de restaurantes ativos) */
final class CatalogoController
{
    public function listar(): Response
    {
        $cursor = is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : null;
        $busca = is_string($_GET['busca'] ?? null) ? $_GET['busca'] : '';
        try {
            $pagina = Catalogo::comBanco(Database::connect())->pagina($cursor, $busca);
            return Response::json($pagina);
        } catch (\InvalidArgumentException) {
            return Response::json(['erro' => 'Página inválida.'], 422);
        } catch (Throwable $exception) {
            error_log('[Cardapim] Catalogo indisponivel: ' . get_class($exception));
            return Response::json(['erro' => 'Não foi possível carregar os restaurantes agora.'], 503);
        }
    }
}
