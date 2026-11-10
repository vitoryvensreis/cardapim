<?php

declare(strict_types=1);

use Cardapim\Controllers\Gerencia\AssinaturaController;
use Cardapim\Controllers\Gerencia\EquipeController;
use Cardapim\Controllers\Gerencia\EstoqueController;
use Cardapim\Controllers\Gerencia\IntegracoesController;
use Cardapim\Controllers\Gerencia\MinhaContaController;
use Cardapim\Controllers\Gerencia\PerfilController;
use Cardapim\Core\Router;

/**
 * Abas funcionais do painel do gerente (/{slug}/gerencia/...).
 * Precisa ser carregado ANTES de '/{slug}/gerencia/{secao}' (rota generica "em construcao").
 */
return static function (Router $router): void {
    $g = '/{slug}/gerencia';

    // Estoque
    $router->get($g . '/estoque', [EstoqueController::class, 'resumo']);
    $router->get($g . '/estoque/produtos', [EstoqueController::class, 'produtos']);
    $router->get($g . '/estoque/produtos/novo', [EstoqueController::class, 'novoProduto']);
    $router->post($g . '/estoque/produtos', [EstoqueController::class, 'criarProduto']);
    $router->get($g . '/estoque/produtos/{id}', [EstoqueController::class, 'editarProduto']);
    $router->post($g . '/estoque/produtos/{id}', [EstoqueController::class, 'atualizarProduto']);
    $router->post($g . '/estoque/produtos/{id}/pausar', [EstoqueController::class, 'alternarProduto']);
    $router->post($g . '/estoque/produtos/{id}/excluir', [EstoqueController::class, 'excluirProduto']);
    $router->get($g . '/estoque/insumos', [EstoqueController::class, 'insumos']);
    $router->get($g . '/estoque/insumos/novo', [EstoqueController::class, 'novoInsumo']);
    $router->post($g . '/estoque/insumos', [EstoqueController::class, 'criarInsumo']);
    $router->get($g . '/estoque/insumos/{id}', [EstoqueController::class, 'editarInsumo']);
    $router->post($g . '/estoque/insumos/{id}', [EstoqueController::class, 'atualizarInsumo']);
    $router->post($g . '/estoque/insumos/{id}/repor', [EstoqueController::class, 'reporInsumo']);
    $router->post($g . '/estoque/insumos/{id}/excluir', [EstoqueController::class, 'excluirInsumo']);
    $router->get($g . '/estoque/categorias', [EstoqueController::class, 'categorias']);
    $router->post($g . '/estoque/categorias', [EstoqueController::class, 'criarCategoria']);
    $router->post($g . '/estoque/categorias/{id}', [EstoqueController::class, 'atualizarCategoria']);
    $router->post($g . '/estoque/categorias/{id}/excluir', [EstoqueController::class, 'excluirCategoria']);

    // Equipe
    $router->get($g . '/equipe', [EquipeController::class, 'lista']);
    $router->get($g . '/equipe/nova', [EquipeController::class, 'nova']);
    $router->post($g . '/equipe', [EquipeController::class, 'criar']);
    $router->get($g . '/equipe/{id}', [EquipeController::class, 'editar']);
    $router->post($g . '/equipe/{id}', [EquipeController::class, 'atualizar']);
    $router->post($g . '/equipe/{id}/senha', [EquipeController::class, 'senha']);
    $router->post($g . '/equipe/{id}/acesso', [EquipeController::class, 'acesso']);
    $router->post($g . '/equipe/{id}/excluir', [EquipeController::class, 'excluir']);

    // Perfil do restaurante
    $router->get($g . '/perfil', [PerfilController::class, 'mostrar']);
    $router->post($g . '/perfil', [PerfilController::class, 'salvarDados']);
    $router->post($g . '/perfil/logo', [PerfilController::class, 'logo']);
    $router->post($g . '/perfil/logo/remover', [PerfilController::class, 'removerLogo']);
    $router->post($g . '/perfil/horarios', [PerfilController::class, 'horarios']);

    // Assinatura
    $router->get($g . '/assinatura', [AssinaturaController::class, 'mostrar']);
    $router->post($g . '/assinatura/assinar', [AssinaturaController::class, 'assinar']);
    $router->post($g . '/assinatura/cancelar', [AssinaturaController::class, 'cancelar']);
    $router->post($g . '/assinatura/reativar', [AssinaturaController::class, 'reativar']);

    // Minha conta (do gerente)
    $router->get($g . '/minha-conta', [MinhaContaController::class, 'mostrar']);
    $router->post($g . '/minha-conta/dados', [MinhaContaController::class, 'dados']);
    $router->post($g . '/minha-conta/senha', [MinhaContaController::class, 'senha']);
    $router->get($g . '/minha-conta/dois-fatores/app', [MinhaContaController::class, 'appIniciar']);
    $router->post($g . '/minha-conta/dois-fatores/app', [MinhaContaController::class, 'appConfirmar']);
    $router->post($g . '/minha-conta/dois-fatores/email', [MinhaContaController::class, 'emailEnviar']);
    $router->get($g . '/minha-conta/dois-fatores/email', [MinhaContaController::class, 'emailForm']);
    $router->post($g . '/minha-conta/dois-fatores/email/confirmar', [MinhaContaController::class, 'emailConfirmar']);
    $router->post($g . '/minha-conta/dois-fatores/desativar', [MinhaContaController::class, 'desativar']);
    $router->get($g . '/minha-conta/codigos', [MinhaContaController::class, 'codigos']);
    $router->post($g . '/minha-conta/codigos', [MinhaContaController::class, 'novosCodigos']);
    $router->post($g . '/minha-conta/aparelhos/sair-todos', [MinhaContaController::class, 'sairOutros']);
    $router->post($g . '/minha-conta/aparelhos/{id}/sair', [MinhaContaController::class, 'sairAparelho']);

    // Integracoes (iFood, WhatsApp, Asaas)
    $router->get($g . '/integracoes', [IntegracoesController::class, 'inicio']);
    $router->get($g . '/integracoes/asaas', [IntegracoesController::class, 'asaas']);
    $router->post($g . '/integracoes/asaas', [IntegracoesController::class, 'asaasConectar']);
    $router->post($g . '/integracoes/asaas/webhook', [IntegracoesController::class, 'asaasWebhook']);
    $router->post($g . '/integracoes/asaas/testar', [IntegracoesController::class, 'asaasTestar']);
    $router->post($g . '/integracoes/asaas/desconectar', [IntegracoesController::class, 'asaasDesconectar']);
    $router->get($g . '/integracoes/whatsapp', [IntegracoesController::class, 'whatsapp']);
    $router->post($g . '/integracoes/whatsapp/conectar', [IntegracoesController::class, 'whatsappConectar']);
    $router->get($g . '/integracoes/whatsapp/estado', [IntegracoesController::class, 'whatsappEstado']);
    $router->post($g . '/integracoes/whatsapp/teste', [IntegracoesController::class, 'whatsappTeste']);
    $router->post($g . '/integracoes/whatsapp/desconectar', [IntegracoesController::class, 'whatsappDesconectar']);
    $router->get($g . '/integracoes/ifood', [IntegracoesController::class, 'ifood']);
    $router->post($g . '/integracoes/ifood/iniciar', [IntegracoesController::class, 'ifoodIniciar']);
    $router->post($g . '/integracoes/ifood/concluir', [IntegracoesController::class, 'ifoodConcluir']);
    $router->post($g . '/integracoes/ifood/loja', [IntegracoesController::class, 'ifoodLoja']);
    $router->post($g . '/integracoes/ifood/testar', [IntegracoesController::class, 'ifoodTestar']);
    $router->post($g . '/integracoes/ifood/cancelar', [IntegracoesController::class, 'ifoodCancelar']);
    $router->post($g . '/integracoes/ifood/desconectar', [IntegracoesController::class, 'ifoodDesconectar']);
};
