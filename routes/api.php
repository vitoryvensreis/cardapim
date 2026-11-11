<?php

declare(strict_types=1);

use Cardapim\Controllers\Api\CatalogoController;
use Cardapim\Controllers\Api\ClienteController;
use Cardapim\Controllers\Api\DisponibilidadeController;
use Cardapim\Controllers\Api\PedidoClienteController;
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
    // Finalizacao do pedido e acompanhamento (cliente final)
    $router->get('/api/v1/cliente/endereco', [PedidoClienteController::class, 'endereco']);
    $router->get('/api/v1/cep/{cep}', [PedidoClienteController::class, 'cep']);
    $router->post('/api/v1/restaurantes/{slug}/entrega', [PedidoClienteController::class, 'entrega']);
    $router->post('/api/v1/restaurantes/{slug}/pedidos', [PedidoClienteController::class, 'criar']);
    $router->get('/api/v1/pedidos/{id}', [PedidoClienteController::class, 'mostrar']);
    $router->get('/api/v1/pedidos/{id}/rastreio', [PedidoClienteController::class, 'rastreio']);
};
