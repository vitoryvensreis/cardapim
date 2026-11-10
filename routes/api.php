<?php

declare(strict_types=1);

use Cardapim\Controllers\Api\CatalogoController;
use Cardapim\Controllers\Api\DisponibilidadeController;
use Cardapim\Controllers\Api\TempoRealController;
use Cardapim\Core\Router;

// Rotas /api/v1 (JSON). Registradas antes da rota coringa /{slug}.
return static function (Router $router): void {
    $router->get('/api/v1/cadastro/disponibilidade', [DisponibilidadeController::class, 'verificar']);
    $router->get('/api/v1/restaurantes', [CatalogoController::class, 'listar']);
    $router->get('/api/v1/tempo-real/ticket', [TempoRealController::class, 'ticket']);
};
