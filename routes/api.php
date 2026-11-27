<?php

declare(strict_types=1);

use Cardapim\Controllers\Api\CatalogoController;
use Cardapim\Controllers\Api\ClienteController;
use Cardapim\Controllers\Api\DisponibilidadeController;
use Cardapim\Controllers\Api\DesktopAgentController;
use Cardapim\Controllers\Api\PedidoClienteController;
use Cardapim\Controllers\Api\TempoRealController;
use Cardapim\Controllers\RestauranteController;
use Cardapim\Core\Router;

// Rotas /api/v1 (JSON). Registradas antes da rota coringa /{slug}.
return static function (Router $router): void {
    $router->get('/api/v1/cadastro/disponibilidade', [DisponibilidadeController::class, 'verificar']);
    $router->get('/api/v1/restaurantes', [CatalogoController::class, 'listar']);
    $router->get('/api/v1/tempo-real/ticket', [TempoRealController::class, 'ticket']);
    // Agente local Windows: login da equipe + vinculo seguro; eventos tenant-scoped por token.
    $router->get('/api/v1/desktop/csrf', [DesktopAgentController::class, 'csrf']);
    $router->post('/api/v1/desktop/vincular', [DesktopAgentController::class, 'vincular']);
    $router->get('/api/v1/desktop/eventos', [DesktopAgentController::class, 'eventos']);
    $router->post('/api/v1/desktop/pesagem', [DesktopAgentController::class, 'registrarPesagem']);
    $router->get('/api/v1/desktop/balancas', [DesktopAgentController::class, 'balancas']);
    $router->post('/api/v1/desktop/pareamento', [DesktopAgentController::class, 'prepararPareamento']);
    $router->get('/api/v1/desktop/parear', [DesktopAgentController::class, 'parearNavegador']);
    $router->post('/api/v1/desktop/desconectar', [DesktopAgentController::class, 'desconectar']);
    $router->get('/api/v1/desktop/pedidos/situacao', [DesktopAgentController::class, 'situacaoPedidos']);
    $router->post('/api/v1/desktop/pedidos/{id}/receber', [DesktopAgentController::class, 'receberPedido']);
    $router->get('/api/v1/desktop/agente-ativo', [DesktopAgentController::class, 'agenteAtivo']);
    $router->post('/api/v1/desktop/impressoes/{id}/resultado', [DesktopAgentController::class, 'resultadoImpressao']);
    $router->post('/api/v1/desktop/impressoras', [DesktopAgentController::class, 'registrarImpressoras']);
    $router->get('/api/v1/restaurantes/{slug}/situacao', [RestauranteController::class, 'situacao']);
    $router->post('/api/v1/restaurantes/{slug}/sacola', [RestauranteController::class, 'sacola']);
    // Cliente final (login pelo WhatsApp da plataforma)
    $router->get('/api/v1/cliente', [ClienteController::class, 'sessao']);
    $router->post('/api/v1/cliente/codigo', [ClienteController::class, 'codigo']);
    $router->post('/api/v1/cliente/entrar', [ClienteController::class, 'entrar']);
    $router->post('/api/v1/cliente/cadastro', [ClienteController::class, 'cadastro']);
    $router->post('/api/v1/cliente/sair', [ClienteController::class, 'sair']);
    // Finalizacao do pedido e acompanhamento (cliente final)
    $router->get('/api/v1/cliente/endereco', [PedidoClienteController::class, 'endereco']);
    $router->get('/api/v1/cliente/pedidos', [PedidoClienteController::class, 'meus']);
    $router->get('/api/v1/cep/{cep}', [PedidoClienteController::class, 'cep']);
    $router->post('/api/v1/restaurantes/{slug}/entrega', [PedidoClienteController::class, 'entrega']);
    $router->post('/api/v1/restaurantes/{slug}/desconto', [PedidoClienteController::class, 'desconto']);
    $router->post('/api/v1/restaurantes/{slug}/pedidos', [PedidoClienteController::class, 'criar']);
    $router->get('/api/v1/pedidos/{id}', [PedidoClienteController::class, 'mostrar']);
    $router->get('/api/v1/pedidos/{id}/rastreio', [PedidoClienteController::class, 'rastreio']);
    $router->post('/api/v1/pedidos/{id}/comprovante', [PedidoClienteController::class, 'comprovante']);
};
