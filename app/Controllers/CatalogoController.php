<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Services\Catalogo;
use Throwable;

final class CatalogoController
{
    public function index(): Response
    {
        $busca = is_string($_GET['busca'] ?? null) ? $_GET['busca'] : '';
        $cursor = is_string($_GET['cursor'] ?? null) ? $_GET['cursor'] : null;
        $pagina = ['itens' => [], 'proximo_cursor' => null];
        $indisponivel = false;
        try {
            $pagina = Catalogo::comBanco(Database::connect())->pagina($cursor, $busca);
        } catch (\InvalidArgumentException) {
            return Response::redirect('/catalogo');
        } catch (Throwable $exception) {
            $indisponivel = true;
            error_log('[Cardapim] Catalogo indisponivel: ' . get_class($exception));
        }

        return Response::html(View::render('catalogo/index', [
            'pageTitle' => 'Restaurantes | Cardapim',
            'pageDescription' => 'Conheça os restaurantes, bares e lanchonetes que usam o Cardapim.',
            'pageStyles' => ['css/catalogo.css'],
            'pageScripts' => ['js/catalogo.js'],
            'restaurantes' => $pagina['itens'],
            'proximoCursor' => $pagina['proximo_cursor'],
            'busca' => mb_substr(trim($busca), 0, 80),
            'indisponivel' => $indisponivel,
            'paginaSeguinte' => $cursor !== null && $cursor !== '',
        ], 'layouts/public'));
    }
}
