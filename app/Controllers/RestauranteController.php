<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Shared\RestauranteLogo;
use Cardapim\Shared\Slug;

/** Pagina publica do restaurante: /{slug}. Cardapio digital ainda em construcao. */
final class RestauranteController
{
    public function show(string $slug): Response
    {
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return $this->naoEncontrado();
        }
        try {
            $restaurante = (new RestauranteRepository(Database::connect()))->buscarPublicoPorSlug($slug);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Pagina do restaurante indisponivel: ' . get_class($exception));
            return Response::html(View::render('errors/500', [
                'pageTitle' => 'Erro temporário | Cardapim',
                'pageDescription' => 'Erro temporário no Cardapim.',
                'noIndex' => true,
            ], 'layouts/public'), 503);
        }
        if ($restaurante === null) {
            return $this->naoEncontrado();
        }

        return Response::html(View::render('restaurante/em-breve', [
            'pageTitle' => $restaurante['nome'] . ' | Cardapim',
            'pageDescription' => 'Cardápio digital de ' . $restaurante['nome'] . ' no Cardapim.',
            'pageStyles' => ['css/catalogo.css'],
            'restaurante' => $restaurante,
            'logo' => RestauranteLogo::urlSegura($restaurante['logo_path']),
            'iniciais' => RestauranteLogo::iniciais($restaurante['nome']),
        ], 'layouts/public'));
    }

    private function naoEncontrado(): Response
    {
        return Response::html(View::render('errors/404', [
            'pageTitle' => 'Página não encontrada | Cardapim',
            'pageDescription' => 'O endereço solicitado não foi encontrado.',
            'noIndex' => true,
        ], 'layouts/public'), 404);
    }
}
