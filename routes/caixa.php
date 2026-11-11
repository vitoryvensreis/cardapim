<?php

declare(strict_types=1);

use Cardapim\Controllers\Caixa\CaixaController;
use Cardapim\Controllers\Caixa\ComandaController;
use Cardapim\Controllers\Caixa\DeliveryController;
use Cardapim\Controllers\Caixa\MesasController;
use Cardapim\Core\Router;

// Modo caixa (caixa, gerente do restaurante ou adm_master). Registrado antes das rotas genericas /{slug}/...
return static function (Router $router): void {
    $c = '/{slug}/caixa';

    // Caixa: abrir, fechar, entradas/saidas e fechamentos
    $router->get($c, [CaixaController::class, 'resumo']);
    $router->post($c . '/abrir', [CaixaController::class, 'abrir']);
    $router->get($c . '/fechar', [CaixaController::class, 'fecharForm']);
    $router->post($c . '/fechar', [CaixaController::class, 'fechar']);
    $router->get($c . '/movimentos', [CaixaController::class, 'movimentos']);
    $router->post($c . '/movimentos', [CaixaController::class, 'registrarMovimento']);
    $router->get($c . '/historico', [CaixaController::class, 'historico']);
    $router->get($c . '/historico/{id}', [CaixaController::class, 'historicoVer']);

    // Mesas: mapa e cadastro
    $router->get($c . '/mesas', [MesasController::class, 'mapa']);
    $router->get($c . '/mesas/gerenciar', [MesasController::class, 'gerenciar']);
    $router->post($c . '/mesas', [MesasController::class, 'criar']);
    $router->post($c . '/mesas/varias', [MesasController::class, 'criarVarias']);
    $router->post($c . '/mesas/nova', [MesasController::class, 'criarProxima']);
    $router->post($c . '/mesas/{id}/abrir', [MesasController::class, 'abrir']);
    $router->post($c . '/mesas/{id}/excluir', [MesasController::class, 'excluir']);
    $router->post($c . '/mesas/{id}', [MesasController::class, 'atualizar']);

    // Comandas (mesa e balcao)
    $router->post($c . '/balcao', [ComandaController::class, 'balcao']);
    $router->get($c . '/comandas/{id}', [ComandaController::class, 'ver']);
    $router->post($c . '/comandas/{id}/itens', [ComandaController::class, 'lancar']);
    $router->post($c . '/comandas/{id}/itens/{item}/cancelar', [ComandaController::class, 'cancelarItem']);
    $router->post($c . '/comandas/{id}/ajustes', [ComandaController::class, 'ajustar']);
    $router->post($c . '/comandas/{id}/transferir', [ComandaController::class, 'transferir']);
    $router->get($c . '/comandas/{id}/pagar', [ComandaController::class, 'pagarForm']);
    $router->post($c . '/comandas/{id}/pagamentos', [ComandaController::class, 'pagamento']);
    $router->post($c . '/comandas/{id}/pagamentos/{pagamento}/remover', [ComandaController::class, 'removerPagamento']);
    $router->post($c . '/comandas/{id}/cancelar', [ComandaController::class, 'cancelar']);

    // Delivery (pedidos do cardapio online)
    $router->get($c . '/delivery', [DeliveryController::class, 'lista']);
    $router->get($c . '/delivery/fila', [DeliveryController::class, 'fila']);
    $router->get($c . '/delivery/{id}', [DeliveryController::class, 'ver']);
    $router->post($c . '/delivery/{id}/{acao}', [DeliveryController::class, 'acao']);
};
