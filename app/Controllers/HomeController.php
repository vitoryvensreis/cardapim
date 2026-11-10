<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\PlanoRepository;
use Throwable;

final class HomeController
{
    public function index(): Response
    {
        $planos = [];
        $planosIndisponiveis = false;
        try {
            $planos = (new PlanoRepository(Database::connect()))->listarPublicos();
        } catch (Throwable $exception) {
            // A landing continua no ar; somente a secao de planos mostra aviso.
            $planosIndisponiveis = true;
            error_log('[Cardapim] Planos indisponiveis na landing: ' . get_class($exception));
        }

        return Response::html(View::render('marketing/home', [
            'pageTitle' => 'Cardapim | Seu restaurante no controle',
            'pageDescription' => 'Uma forma mais simples de organizar pedidos, mesas, caixa, cozinha e delivery em um unico lugar.',
            'planos' => $planos,
            'planosIndisponiveis' => $planosIndisponiveis,
        ]));
    }
}
