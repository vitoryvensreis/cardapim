<?php

declare(strict_types=1);

use Cardapim\Controllers\GarcomController;
use Cardapim\Core\Router;

// Painel do garcom (garcom, gerente do restaurante ou adm_master). Registrado antes das rotas genericas /{slug}/...
return static function (Router $router): void {
    $g = '/{slug}/garcom';
    $router->get($g, [GarcomController::class, 'mesas']);
    $router->post($g . '/mesas/{id}/abrir', [GarcomController::class, 'abrirMesa']);
    $router->get($g . '/comandas/{id}', [GarcomController::class, 'comanda']);
    $router->post($g . '/comandas/{id}/itens', [GarcomController::class, 'lancar']);
    $router->post($g . '/comandas/{id}/itens/{item}/cancelar', [GarcomController::class, 'cancelarItem']);
    $router->get($g . '/comandas/{id}/conta', [GarcomController::class, 'conta']);
    $router->post($g . '/comandas/{id}/servico', [GarcomController::class, 'servico']);
    $router->post($g . '/comandas/{id}/pagamentos', [GarcomController::class, 'pagar']);
    $router->post($g . '/comandas/{id}/pix', [GarcomController::class, 'gerarPix']);
    $router->get($g . '/pix/{id}', [GarcomController::class, 'verPix']);
    $router->post($g . '/pix/{id}/cancelar', [GarcomController::class, 'cancelarPix']);
    $router->get($g . '/cardapio', [GarcomController::class, 'cardapioTela']);
};
