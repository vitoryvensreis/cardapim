<?php

declare(strict_types=1);

use Cardapim\Controllers\Gerencia\AssinaturaController;
use Cardapim\Controllers\Gerencia\CozinhaController;
use Cardapim\Controllers\Gerencia\CuponsController;
use Cardapim\Controllers\Gerencia\EntregaPagamentoController;
use Cardapim\Controllers\Gerencia\FidelidadeController;
use Cardapim\Controllers\Gerencia\EquipeController;
use Cardapim\Controllers\Gerencia\EstoqueController;
use Cardapim\Controllers\Gerencia\FinanceiroController;
use Cardapim\Controllers\Gerencia\ImportarCardapioController;
use Cardapim\Controllers\Gerencia\ImpressorasController;
use Cardapim\Controllers\Gerencia\FiscalController;
use Cardapim\Controllers\Gerencia\IntegracoesController;
use Cardapim\Controllers\Gerencia\SuporteController;
use Cardapim\Controllers\Gerencia\MinhaContaController;
use Cardapim\Controllers\Gerencia\PerfilController;
use Cardapim\Controllers\Gerencia\VisaoGeralController;
use Cardapim\Core\Router;

/**
 * Abas funcionais do painel do gerente (/{slug}/gerencia/...).
 * Precisa ser carregado ANTES de '/{slug}/gerencia/{secao}' (rota generica "em construcao").
 */
return static function (Router $router): void {
    $g = '/{slug}/gerencia';

    // Visao geral (primeira tela)
    $router->get($g, [VisaoGeralController::class, 'mostrar']);

    // Cozinha: apenas leitura, em layout de tela cheia.
    $router->get($g . '/cozinha', [CozinhaController::class, 'mostrar']);
    $router->get($g . '/cozinha/conteudo', [CozinhaController::class, 'conteudo']);

    // Estoque
    $router->get($g . '/estoque', [EstoqueController::class, 'resumo']);
    $router->get($g . '/estoque/produtos', [EstoqueController::class, 'produtos']);
    $router->get($g . '/estoque/produtos/novo', [EstoqueController::class, 'novoProduto']);
    $router->post($g . '/estoque/produtos', [EstoqueController::class, 'criarProduto']);
    $router->get($g . '/estoque/produtos/{id}', [EstoqueController::class, 'editarProduto']);
    $router->post($g . '/estoque/produtos/{id}', [EstoqueController::class, 'atualizarProduto']);
    $router->post($g . '/estoque/produtos/{id}/pausar', [EstoqueController::class, 'alternarProduto']);
    $router->post($g . '/estoque/produtos/{id}/excluir', [EstoqueController::class, 'excluirProduto']);
    $router->get($g . '/estoque/complementos', [EstoqueController::class, 'complementos']);
    $router->get($g . '/estoque/complementos/novo', [EstoqueController::class, 'novoGrupo']);
    $router->post($g . '/estoque/complementos', [EstoqueController::class, 'criarGrupo']);
    $router->post($g . '/estoque/complementos/opcoes/{id}/pausar', [EstoqueController::class, 'alternarOpcao']);
    $router->get($g . '/estoque/complementos/{id}', [EstoqueController::class, 'editarGrupo']);
    $router->post($g . '/estoque/complementos/{id}', [EstoqueController::class, 'atualizarGrupo']);
    $router->post($g . '/estoque/complementos/{id}/excluir', [EstoqueController::class, 'excluirGrupo']);
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
    $router->post($g . '/estoque/categorias/{id}/impressoras', [EstoqueController::class, 'impressorasCategoria']);
    $router->post($g . '/estoque/categorias/{id}/excluir', [EstoqueController::class, 'excluirCategoria']);

    // Equipe
    $router->get($g . '/equipe', [EquipeController::class, 'lista']);
    $router->get($g . '/equipe/nova', [EquipeController::class, 'nova']);
    $router->post($g . '/equipe', [EquipeController::class, 'criar']);
    $router->post($g . '/equipe/regras/entregador', [EquipeController::class, 'regrasEntregador']);
    $router->post($g . '/equipe/regras/garcom', [EquipeController::class, 'regrasGarcom']);
    $router->get($g . '/equipe/{id}', [EquipeController::class, 'editar']);
    $router->post($g . '/equipe/{id}', [EquipeController::class, 'atualizar']);
    $router->post($g . '/equipe/{id}/senha', [EquipeController::class, 'senha']);
    $router->post($g . '/equipe/{id}/acesso', [EquipeController::class, 'acesso']);
    $router->post($g . '/equipe/{id}/excluir', [EquipeController::class, 'excluir']);

    // Financeiro
    $router->get($g . '/financeiro', [FinanceiroController::class, 'resumo']);
    $router->get($g . '/financeiro/extrato', [FinanceiroController::class, 'extrato']);
    $router->get($g . '/financeiro/fechamentos', [FinanceiroController::class, 'fechamentos']);
    $router->get($g . '/financeiro/relatorio', [FinanceiroController::class, 'planilha']);
    $router->get($g . '/financeiro/contas', [FinanceiroController::class, 'contas']);
    $router->get($g . '/financeiro/contas/nova', [FinanceiroController::class, 'novaConta']);
    $router->post($g . '/financeiro/contas', [FinanceiroController::class, 'criarConta']);
    $router->get($g . '/financeiro/contas/{id}', [FinanceiroController::class, 'editarConta']);
    $router->post($g . '/financeiro/contas/{id}', [FinanceiroController::class, 'atualizarConta']);
    $router->post($g . '/financeiro/contas/{id}/baixar', [FinanceiroController::class, 'baixarConta']);
    $router->post($g . '/financeiro/contas/{id}/reabrir', [FinanceiroController::class, 'reabrirConta']);
    $router->post($g . '/financeiro/contas/{id}/excluir', [FinanceiroController::class, 'excluirConta']);

    // Perfil do restaurante
    $router->get($g . '/perfil', [PerfilController::class, 'mostrar']);
    $router->post($g . '/perfil', [PerfilController::class, 'salvarDados']);
    $router->post($g . '/perfil/logo', [PerfilController::class, 'logo']);
    $router->post($g . '/perfil/logo/remover', [PerfilController::class, 'removerLogo']);
    $router->post($g . '/perfil/horarios', [PerfilController::class, 'horarios']);

    // Entrega e pagamento (cardapio online)
    // Clientes: cupons de desconto e programa de fidelidade (cardapio online)
    $router->get($g . '/cupons', [CuponsController::class, 'lista']);
    $router->get($g . '/cupons/novo', [CuponsController::class, 'novo']);
    $router->post($g . '/cupons', [CuponsController::class, 'criar']);
    $router->get($g . '/cupons/{id}', [CuponsController::class, 'editar']);
    $router->post($g . '/cupons/{id}', [CuponsController::class, 'atualizar']);
    $router->post($g . '/cupons/{id}/pausar', [CuponsController::class, 'pausar']);
    $router->post($g . '/cupons/{id}/excluir', [CuponsController::class, 'excluir']);
    $router->get($g . '/fidelidade', [FidelidadeController::class, 'mostrar']);
    $router->post($g . '/fidelidade', [FidelidadeController::class, 'salvar']);

    $router->get($g . '/entrega-pagamento', [EntregaPagamentoController::class, 'mostrar']);
    $router->post($g . '/entrega-pagamento', [EntregaPagamentoController::class, 'salvar']);
    $router->get($g . '/entrega-pagamento/testar', [EntregaPagamentoController::class, 'testar']);
    $router->post($g . '/entrega-pagamento/entregador', [EntregaPagamentoController::class, 'entregador']);
    $router->post($g . '/entrega-pagamento/garcom', [EntregaPagamentoController::class, 'salvarGarcom']);
    $router->post($g . '/entrega-pagamento/areas', [EntregaPagamentoController::class, 'criarArea']);
    $router->post($g . '/entrega-pagamento/areas/{id}', [EntregaPagamentoController::class, 'atualizarArea']);
    $router->post($g . '/entrega-pagamento/areas/{id}/pausar', [EntregaPagamentoController::class, 'alternarArea']);
    $router->post($g . '/entrega-pagamento/areas/{id}/excluir', [EntregaPagamentoController::class, 'excluirArea']);
    $router->post($g . '/entrega-pagamento/localizacao', [EntregaPagamentoController::class, 'local']);
    $router->post($g . '/entrega-pagamento/localizacao/remover', [EntregaPagamentoController::class, 'removerLocal']);

    // Assinatura
    $router->get($g . '/assinatura', [AssinaturaController::class, 'mostrar']);
    $router->post($g . '/assinatura/assinar', [AssinaturaController::class, 'assinar']);
    $router->post($g . '/assinatura/cancelar', [AssinaturaController::class, 'cancelar']);
    $router->post($g . '/assinatura/reativar', [AssinaturaController::class, 'reativar']);
    $router->post($g . '/assinatura/cobranca', [AssinaturaController::class, 'cobranca']);

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
    $router->get($g . '/fiscal', [FiscalController::class, 'inicio']);
    $router->post($g . '/fiscal/habilitar', [FiscalController::class, 'habilitar']);
    $router->post($g . '/fiscal/empresa', [FiscalController::class, 'empresa']);
    $router->post($g . '/fiscal/conectar', [FiscalController::class, 'conectar']);
    $router->post($g . '/fiscal/ambiente', [FiscalController::class, 'ambiente']);
    $router->post($g . '/fiscal/configurar', [FiscalController::class, 'configurar']);
    $router->post($g . '/fiscal/certificado', [FiscalController::class, 'certificado']);
    $router->post($g . '/fiscal/enviar-config', [FiscalController::class, 'enviarConfig']);
    $router->post($g . '/fiscal/testar', [FiscalController::class, 'testar']);
    $router->post($g . '/integracoes/impressoras/fiscal', [ImpressorasController::class, 'fiscal']);
    $router->get($g . '/integracoes/impressoras', [ImpressorasController::class, 'inicio']);
    $router->post($g . '/integracoes/impressoras', [ImpressorasController::class, 'salvar']);
    $router->post($g . '/integracoes/impressoras/teste', [ImpressorasController::class, 'teste']);
    $router->post($g . '/integracoes/impressoras/aparencia', [ImpressorasController::class, 'aparencia']);
    $router->post($g . '/integracoes/impressoras/aparencia/previa', [ImpressorasController::class, 'previa']);
    $router->get($g . '/integracoes/asaas', [IntegracoesController::class, 'asaas']);
    $router->post($g . '/integracoes/asaas', [IntegracoesController::class, 'asaasConectar']);
    $router->post($g . '/integracoes/asaas/webhook', [IntegracoesController::class, 'asaasWebhook']);
    $router->post($g . '/integracoes/asaas/testar', [IntegracoesController::class, 'asaasTestar']);
    $router->post($g . '/integracoes/asaas/desconectar', [IntegracoesController::class, 'asaasDesconectar']);
    $router->get($g . '/integracoes/whatsapp', [IntegracoesController::class, 'whatsapp']);
    $router->post($g . '/integracoes/whatsapp/conectar', [IntegracoesController::class, 'whatsappConectar']);
    $router->get($g . '/integracoes/whatsapp/estado', [IntegracoesController::class, 'whatsappEstado']);
    $router->post($g . '/integracoes/whatsapp/teste', [IntegracoesController::class, 'whatsappTeste']);
    $router->post($g . '/integracoes/whatsapp/avisos', [IntegracoesController::class, 'whatsappAvisos']);
    $router->post($g . '/integracoes/whatsapp/saudacao', [IntegracoesController::class, 'whatsappSaudacao']);
    $router->post($g . '/integracoes/whatsapp/saudacao/webhook', [IntegracoesController::class, 'whatsappSaudacaoWebhook']);
    $router->post($g . '/integracoes/whatsapp/desconectar', [IntegracoesController::class, 'whatsappDesconectar']);
    // Importar cardapio por planilha (CSV/XLSX): enviar -> conferir -> importar.
    $router->get($g . '/integracoes/importar-cardapio', [ImportarCardapioController::class, 'inicio']);
    $router->get($g . '/integracoes/importar-cardapio/modelo', [ImportarCardapioController::class, 'modelo']);
    $router->post($g . '/integracoes/importar-cardapio/conferir', [ImportarCardapioController::class, 'conferir']);
    $router->post($g . '/integracoes/importar-cardapio/importar', [ImportarCardapioController::class, 'importar']);
    $router->post($g . '/integracoes/importar-cardapio/descartar', [ImportarCardapioController::class, 'descartar']);
    $router->get($g . '/suporte', [SuporteController::class, 'lista']);
    $router->get($g . '/suporte/novo', [SuporteController::class, 'novo']);
    $router->post($g . '/suporte', [SuporteController::class, 'criar']);
    $router->get($g . '/suporte/{id}', [SuporteController::class, 'ver']);
    $router->post($g . '/suporte/{id}/responder', [SuporteController::class, 'responderChamado']);
    $router->post($g . '/suporte/{id}/resolver', [SuporteController::class, 'resolver']);
    // Abas independentes: entrada legada redireciona para Status.
    $router->get($g . '/integracoes/ifood', [IntegracoesController::class, 'ifood']);
    $router->get($g . '/integracoes/ifood/status', [IntegracoesController::class, 'ifoodStatus']);
    $router->get($g . '/integracoes/ifood/loja', [IntegracoesController::class, 'ifoodAbaLoja']);
    $router->get($g . '/integracoes/ifood/cardapio', [IntegracoesController::class, 'ifoodAbaCardapio']);
    $router->get($g . '/integracoes/ifood/dados', [IntegracoesController::class, 'ifoodDados']);
    $router->post($g . '/integracoes/ifood/iniciar', [IntegracoesController::class, 'ifoodIniciar']);
    $router->post($g . '/integracoes/ifood/concluir', [IntegracoesController::class, 'ifoodConcluir']);
    $router->post($g . '/integracoes/ifood/loja', [IntegracoesController::class, 'ifoodLoja']);
    $router->post($g . '/integracoes/ifood/testar', [IntegracoesController::class, 'ifoodTestar']);
    $router->post($g . '/integracoes/ifood/cancelar', [IntegracoesController::class, 'ifoodCancelar']);
    $router->post($g . '/integracoes/ifood/desconectar', [IntegracoesController::class, 'ifoodDesconectar']);
    $router->post($g . '/integracoes/ifood/opcoes', [IntegracoesController::class, 'ifoodOpcoes']);
    $router->get($g . '/integracoes/ifood/cardapio/vinculos', [IntegracoesController::class, 'ifoodCardapio']);
    $router->post($g . '/integracoes/ifood/cardapio/buscar', [IntegracoesController::class, 'ifoodCardapioBuscar']);
    $router->post($g . '/integracoes/ifood/cardapio/ligar', [IntegracoesController::class, 'ifoodCardapioLigar']);
    $router->post($g . '/integracoes/ifood/cardapio/sincronizar', [IntegracoesController::class, 'ifoodSincronizar']);
    // Cardapio publicado no iFood (Catalog): categoria, item, foto, complementos; preco e pausa por PATCH.
    $router->get($g . '/integracoes/ifood/publicar', [IntegracoesController::class, 'ifoodPublicar']);
    $router->post($g . '/integracoes/ifood/publicar', [IntegracoesController::class, 'ifoodPublicarEnviar']);
    $router->post($g . '/integracoes/ifood/publicar/sincronizar', [IntegracoesController::class, 'ifoodPublicarSincronizar']);
    // Loja no iFood (Merchant): dados, situacao, pausas e horarios.
    $router->get($g . '/integracoes/ifood/minha-loja', [IntegracoesController::class, 'ifoodMinhaLoja']);
    $router->post($g . '/integracoes/ifood/pausas', [IntegracoesController::class, 'ifoodPausaCriar']);
    $router->post($g . '/integracoes/ifood/pausas/remover', [IntegracoesController::class, 'ifoodPausaRemover']);
    $router->post($g . '/integracoes/ifood/pausas/rapida', [IntegracoesController::class, 'ifoodPausaRapida']);
    $router->get($g . '/integracoes/ifood/pausas/estado', [IntegracoesController::class, 'ifoodPausaEstado']);
    $router->post($g . '/integracoes/ifood/horarios', [IntegracoesController::class, 'ifoodHorarios']);
    // Botao "Abrir loja no iFood" do topo (JSON).
    $router->get($g . '/integracoes/ifood/botao-loja/situacao', [IntegracoesController::class, 'ifoodBotaoSituacao']);
    $router->post($g . '/integracoes/ifood/botao-loja/abrir', [IntegracoesController::class, 'ifoodBotaoAbrir']);
};
