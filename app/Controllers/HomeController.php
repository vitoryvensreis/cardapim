<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Response;
use Cardapim\Core\View;

final class HomeController
{
    public function index(): Response
    {
        return Response::html(View::render('marketing/home', [
            'pageTitle' => 'Cardapim | Seu restaurante no controle',
            'pageDescription' => 'Uma forma mais simples de organizar pedidos, mesas, caixa, cozinha e delivery em um unico lugar.',
        ]));
    }
}
