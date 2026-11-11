<?php

declare(strict_types=1);

use Cardapim\Controllers\Plataforma\IntegracoesController;
use Cardapim\Controllers\Plataforma\PainelController;
use Cardapim\Core\Router;

// Painel da PLATAFORMA (adm_master). Registrado antes das rotas /{slug}. Acesso conferido em BasePlataforma.
return static function (Router $router): void {
    $router->get('/painel', [PainelController::class, 'inicio']);

    $router->get('/painel/integracoes', [IntegracoesController::class, 'inicio']);
    $router->get('/painel/integracoes/asaas', [IntegracoesController::class, 'asaas']);
    $router->post('/painel/integracoes/asaas', [IntegracoesController::class, 'asaasConectar']);
    $router->post('/painel/integracoes/asaas/webhook', [IntegracoesController::class, 'asaasWebhook']);
    $router->post('/painel/integracoes/asaas/testar', [IntegracoesController::class, 'asaasTestar']);
    $router->post('/painel/integracoes/asaas/desconectar', [IntegracoesController::class, 'asaasDesconectar']);
    $router->post('/painel/integracoes/asaas/reativar', [IntegracoesController::class, 'asaasReativar']);
    $router->post('/painel/integracoes/asaas/cobranca', [IntegracoesController::class, 'asaasCobranca']);
    $router->get('/painel/integracoes/whatsapp', [IntegracoesController::class, 'whatsapp']);
    $router->post('/painel/integracoes/whatsapp/conectar', [IntegracoesController::class, 'whatsappConectar']);
    $router->get('/painel/integracoes/whatsapp/estado', [IntegracoesController::class, 'whatsappEstado']);
    $router->post('/painel/integracoes/whatsapp/teste', [IntegracoesController::class, 'whatsappTeste']);
    $router->post('/painel/integracoes/whatsapp/desconectar', [IntegracoesController::class, 'whatsappDesconectar']);

    // Demais secoes (em construcao): dashboard, restaurantes, planos, faturas, usuarios, logs...
    $router->get('/painel/{secao}', [PainelController::class, 'secao']);
};
