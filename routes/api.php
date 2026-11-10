<?php

declare(strict_types=1);

use Cardapim\Controllers\Api\CatalogoController;
use Cardapim\Controllers\Api\ClienteController;
use Cardapim\Controllers\Api\DisponibilidadeController;
use Cardapim\Controllers\Api\TempoRealController;
use Cardapim\Controllers\RestauranteController;
use Cardapim\Core\Router;

// Rotas /api/v1 (JSON). Registradas antes da rota coringa /{slug}.
return static function (Router $router): void {
    $router->get('/api/v1/cadastro/disponibilidade', [DisponibilidadeController::class, 'verificar']);
    $router->get('/api/v1/restaurantes', [CatalogoController::class, 'listar']);
    $router->get('/api/v1/tempo-real/ticket', [TempoRealController::class, 'ticket']);
    $router->post('/api/v1/restaurantes/{slug}/sacola', [RestauranteController::class, 'sacola']);
    // Cliente final (login pelo WhatsApp da plataforma)
    $router->get('/api/v1/cliente', [ClienteController::class, 'sessao']);
    $router->post('/api/v1/cliente/codigo', [ClienteController::class, 'codigo']);
    $router->post('/api/v1/cliente/entrar', [ClienteController::class, 'entrar']);
    $router->post('/api/v1/cliente/cadastro', [ClienteController::class, 'cadastro']);
    $router->post('/api/v1/cliente/sair', [ClienteController::class, 'sair']);
};
