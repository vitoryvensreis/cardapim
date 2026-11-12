<?php

declare(strict_types=1);

use Cardapim\Controllers\Plataforma\ConfiguracoesController;
use Cardapim\Controllers\Plataforma\IntegracoesController;
use Cardapim\Controllers\Plataforma\PainelController;
use Cardapim\Controllers\Plataforma\PlanosController;
use Cardapim\Controllers\Plataforma\RestaurantesController;
use Cardapim\Controllers\Plataforma\SuporteController;
use Cardapim\Controllers\Plataforma\FaturasController;
use Cardapim\Controllers\Plataforma\UsuariosController;
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

    // Planos: publicos (site) e privados (link de convite), vitalicio gratis.
    $router->get('/painel/planos', [PlanosController::class, 'lista']);
    $router->get('/painel/planos/novo', [PlanosController::class, 'novo']);
    $router->post('/painel/planos', [PlanosController::class, 'criar']);
    $router->get('/painel/planos/{id}', [PlanosController::class, 'editar']);
    $router->post('/painel/planos/{id}', [PlanosController::class, 'atualizar']);
    $router->post('/painel/planos/{id}/convite', [PlanosController::class, 'convite']);
    $router->post('/painel/planos/{id}/ativo', [PlanosController::class, 'alternar']);
    $router->post('/painel/planos/{id}/excluir', [PlanosController::class, 'excluir']);

    // Restaurantes: lista, cadastrar (com gerente e plano), ver/editar, situacao, plano e excluir (sem movimento).
    $router->get('/painel/restaurantes', [RestaurantesController::class, 'lista']);
    $router->get('/painel/restaurantes/novo', [RestaurantesController::class, 'novo']);
    $router->post('/painel/restaurantes', [RestaurantesController::class, 'criar']);
    $router->get('/painel/restaurantes/{id}', [RestaurantesController::class, 'ver']);
    $router->post('/painel/restaurantes/{id}', [RestaurantesController::class, 'salvar']);
    $router->post('/painel/restaurantes/{id}/status', [RestaurantesController::class, 'status']);
    $router->post('/painel/restaurantes/{id}/plano', [RestaurantesController::class, 'plano']);
    $router->post('/painel/restaurantes/{id}/excluir', [RestaurantesController::class, 'excluir']);

    // Usuarios: administradores e equipe de todos os restaurantes.
    $router->get('/painel/usuarios', [UsuariosController::class, 'lista']);
    $router->get('/painel/usuarios/novo', [UsuariosController::class, 'novo']);
    $router->post('/painel/usuarios', [UsuariosController::class, 'criar']);
    $router->get('/painel/usuarios/{id}', [UsuariosController::class, 'editar']);
    $router->post('/painel/usuarios/{id}', [UsuariosController::class, 'atualizar']);
    $router->post('/painel/usuarios/{id}/senha', [UsuariosController::class, 'senha']);
    $router->post('/painel/usuarios/{id}/acesso', [UsuariosController::class, 'acesso']);
    $router->post('/painel/usuarios/{id}/dois-fatores', [UsuariosController::class, 'doisFatores']);
    $router->post('/painel/usuarios/{id}/excluir', [UsuariosController::class, 'excluir']);

    // Configuracoes: nome da marca, textos e logos.
    $router->get('/painel/configuracoes', [ConfiguracoesController::class, 'inicio']);
    $router->post('/painel/configuracoes/textos', [ConfiguracoesController::class, 'textos']);
    $router->post('/painel/configuracoes/logo', [ConfiguracoesController::class, 'logo']);
    $router->post('/painel/configuracoes/logo/padrao', [ConfiguracoesController::class, 'logoPadrao']);

    // Suporte: chamados dos restaurantes.
    $router->get('/painel/suporte', [SuporteController::class, 'lista']);
    $router->get('/painel/suporte/{id}', [SuporteController::class, 'ver']);
    $router->post('/painel/suporte/{id}/responder', [SuporteController::class, 'responderChamado']);
    $router->post('/painel/suporte/{id}/status', [SuporteController::class, 'status']);

    // Faturas das assinaturas (Asaas da plataforma).
    $router->get('/painel/faturas', [FaturasController::class, 'lista']);
    $router->post('/painel/faturas/atualizar', [FaturasController::class, 'atualizar']);

    // E-mail de teste (Configuracoes).
    $router->post('/painel/configuracoes/email-teste', [ConfiguracoesController::class, 'emailTeste']);

    // Demais secoes (em construcao): dashboard, restaurantes, planos, faturas, usuarios, logs...
    $router->get('/painel/{secao}', [PainelController::class, 'secao']);
};
