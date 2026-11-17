<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\Integracoes\AsaasService;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Gerencia\Integracoes\IfoodService;
use Cardapim\Services\Gerencia\Integracoes\WhatsAppService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\BotaoLojaIfood;
use Cardapim\Services\Ifood\IfoodCatalogo;
use Cardapim\Services\Ifood\IfoodConta;
use Cardapim\Services\Ifood\IfoodDiagnostico;
use Cardapim\Shared\Seguranca\Cripto;

/** Aba Integracoes: iFood, WhatsApp (WPPConnect) e Asaas. Rotas em /{slug}/gerencia/integracoes/... */
final class IntegracoesController extends BaseGerencia
{
    public function inicio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('gerencia/integracoes/inicio', 'integracoes', 'Integrações', [
            'integracoes' => (new IntegracaoRepository($this->db))->todas($this->restaurante['id']),
            'ifoodDisponivel' => IfoodApi::disponivel(),
            'whatsappDisponivel' => WppConnectApi::disponivel(),
        ]);
    }

    /* ---------- Asaas ---------- */

    public function asaas(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->telaAsaas([], []);
    }

    public function asaasConectar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar($this->url('integracoes/asaas'), 'erro', 'A criptografia do servidor não está disponível. Avise o suporte.');
        }
        $resultado = $this->asaasServico()->conectar($this->post('ambiente'), (string) ($_POST['chave'] ?? ''), $this->post('email_avisos'));
        if ($resultado->ok) {
            return $this->voltar($this->url('integracoes/asaas'), 'sucesso', $resultado->mensagem);
        }
        return $this->telaAsaas($resultado->erros + ['_geral' => $resultado->mensagem], ['ambiente' => $this->post('ambiente'), 'email_avisos' => $this->post('email_avisos')], 422);
    }

    public function asaasWebhook(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->asaasServico()->configurarWebhook(), 'integracoes/asaas');
    }

    public function asaasTestar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->asaasServico()->testar(), 'integracoes/asaas');
    }

    public function asaasDesconectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->asaasServico()->desconectar(), 'integracoes/asaas');
    }

    /* ---------- WhatsApp ---------- */

    public function whatsapp(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $integracao = (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'whatsapp');
        AvisosWhatsApp::agendarEnvio((int) $this->restaurante['id']); // tenta de novo o que ficou na fila
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'integracao' => $integracao,
            'disponivel' => WppConnectApi::disponivel(),
            'erros' => [],
            'valores' => ['mensagem' => 'Olá! Esta é uma mensagem de teste do ' . $this->restaurante['nome'] . ' pelo ' . marca() . '.'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ] + $this->dadosAvisos());
    }

    public function whatsappConectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->whatsappServico()->conectar(), 'integracoes/whatsapp');
    }

    /** JSON consultado pela tela enquanto o QR Code esta aberto. */
    public function whatsappEstado(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return Response::json(['estado' => 'sessao', 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 401);
        }
        return Guard::semCache(Response::json($this->whatsappServico()->estado()));
    }

    public function whatsappTeste(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = $this->whatsappServico()->enviarTeste($this->post('telefone'), (string) ($_POST['mensagem'] ?? ''));
        if ($resultado->ok || $resultado->erros === []) {
            return $this->responder($resultado, $this->url('integracoes/whatsapp') . '#teste');
        }
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'integracao' => (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'whatsapp'),
            'disponivel' => WppConnectApi::disponivel(),
            'erros' => $resultado->erros,
            'valores' => ['telefone' => $this->post('telefone'), 'mensagem' => (string) ($_POST['mensagem'] ?? '')],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ] + $this->dadosAvisos(), 422);
    }

    /** Quais avisos de pedido o WhatsApp do restaurante manda para os clientes. */
    public function whatsappAvisos(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $escolhidos = array_values(array_filter((array) ($_POST['avisos'] ?? []), 'is_string'));
        $antes = AvisosWhatsApp::ligados($this->db, (int) $this->restaurante['id']);
        AvisosWhatsApp::salvarLigados($this->db, (int) $this->restaurante['id'], $escolhidos);
        $depois = AvisosWhatsApp::ligados($this->db, (int) $this->restaurante['id']);
        $nomes = static fn (array $l): string => $l === [] ? 'nenhum' : implode(', ', array_map(static fn (string $e): string => AvisosWhatsApp::EVENTOS[$e][0], $l));
        \Cardapim\Services\Auditoria::registrar($this->usuario, 'WhatsApp: avisos aos clientes', 'Antes: ' . $nomes($antes) . '. Agora: ' . $nomes($depois) . '.');
        return $this->voltar($this->url('integracoes/whatsapp') . '#avisos', 'sucesso', $depois === []
            ? 'Avisos desligados. Os clientes não vão receber mensagens automáticas.'
            : 'Avisos salvos. Os clientes vão receber: ' . mb_strtolower($nomes($depois)) . '.');
    }

    /** @return array{avisosLigados:list<string>,avisosRecentes:list<array<string,mixed>>} */
    private function dadosAvisos(): array
    {
        return [
            'avisosLigados' => AvisosWhatsApp::ligados($this->db, (int) $this->restaurante['id']),
            'avisosRecentes' => AvisosWhatsApp::recentes($this->db, (int) $this->restaurante['id']),
        ];
    }

    public function whatsappDesconectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->whatsappServico()->desconectar(), 'integracoes/whatsapp');
    }

    /* ---------- iFood ---------- */

    public function ifood(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->telaIfood();
    }

    /** Tela unica de operacao e configuracao iFood, incluindo os formularios com erro. */
    private function telaIfood(array $erros = [], array $valores = [], int $status = 200): Response
    {
        $conta = new IfoodConta($this->db, (int) $this->restaurante['id']);
        $itens = $conta->conectada() ? (new IfoodCatalogo($this->db, (int) $this->restaurante['id']))->itens() : [];
        $hoje = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND origem = 'ifood' AND criado_em >= ?");
        $hoje->execute([$this->restaurante['id'], date('Y-m-d 00:00:00')]);
        return $this->tela('gerencia/integracoes/ifood', 'integracoes', 'iFood', [
            'integracao' => (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'ifood'),
            'disponivel' => IfoodApi::disponivel(),
            'opcoes' => ['aceitar_automatico' => $conta->opcao('aceitar_automatico'), 'sincronizar_estoque' => $conta->opcao('sincronizar_estoque')],
            'resumoCardapio' => [
                'total' => count($itens),
                'ligados' => count(array_filter($itens, static fn (array $i): bool => $i['id_produto'] !== null)),
                'erros' => count(array_filter($itens, static fn (array $i): bool => $i['ultimo_erro'] !== null)),
            ],
            'pedidosHoje' => (int) $hoje->fetchColumn(),
            'painel' => $conta->conectada() ? $this->ifoodLojaServico()->painel() : null,
            'diagnosticoMerchant' => $conta->conectada() ? IfoodDiagnostico::recentes((int) $this->restaurante['id'], 30) : [],
            'erros' => $erros,
            'valores' => $valores,
            'pageStyles' => ['css/ifood.css'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    public function ifoodIniciar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->iniciar(), 'integracoes/ifood');
    }

    public function ifoodConcluir(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->concluir($this->post('codigo')), 'integracoes/ifood');
    }

    public function ifoodLoja(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->escolherLoja($this->post('loja')), 'integracoes/ifood');
    }

    public function ifoodTestar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->testar(), 'integracoes/ifood');
    }

    public function ifoodCancelar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->cancelar(), 'integracoes/ifood');
    }

    public function ifoodDesconectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->desconectar(), 'integracoes/ifood');
    }

    public function ifoodOpcoes(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->salvarOpcoes(isset($_POST['aceitar_automatico']), isset($_POST['sincronizar_estoque'])), 'integracoes/ifood');
    }

    /** Cardapio do iFood: cada item ligado a um produto (estoque e disponibilidade). */
    public function ifoodCardapio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $conta = new IfoodConta($this->db, (int) $this->restaurante['id']);
        if (!$conta->conectada()) {
            return $this->voltar($this->url('integracoes/ifood'), 'erro', 'Conecte o iFood primeiro.');
        }
        $catalogo = new IfoodCatalogo($this->db, (int) $this->restaurante['id']);
        $filtro = in_array($_GET['filtro'] ?? '', ['soltos', 'erros'], true) ? (string) $_GET['filtro'] : 'todos';
        $itens = $catalogo->itens();
        return $this->tela('gerencia/integracoes/ifood-cardapio', 'integracoes', 'Cardápio do iFood', [
            'itens' => $itens,
            'filtro' => $filtro,
            'produtos' => $catalogo->produtos(),
            'sincronizar' => $conta->opcao('sincronizar_estoque'),
            'pageStyles' => ['css/ifood.css'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ]);
    }

    public function ifoodCardapioBuscar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->buscarCardapio(), 'integracoes/ifood/cardapio');
    }

    public function ifoodCardapioLigar(string $slug): Response
    {
        $escolhas = is_array($_POST['produto'] ?? null) ? $_POST['produto'] : [];
        $volta = 'integracoes/ifood/cardapio' . (in_array($_POST['filtro'] ?? '', ['soltos', 'erros'], true) ? '?filtro=' . $_POST['filtro'] : '');
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->salvarLigacoes($escolhas), $volta);
    }

    public function ifoodSincronizar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->sincronizarAgora(), 'integracoes/ifood/cardapio');
    }

    /* ---------- Cardapio publicado no iFood (Catalog) ---------- */

    public function ifoodPublicar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $conta = new IfoodConta($this->db, (int) $this->restaurante['id']);
        if (!$conta->conectada()) {
            return $this->voltar($this->url('integracoes/ifood'), 'erro', 'Conecte o iFood primeiro.');
        }
        $pronto = \Cardapim\Services\Ifood\IfoodPublicacao::disponivel($this->db);
        return $this->tela('gerencia/integracoes/ifood-publicar', 'integracoes', 'Publicar no iFood', [
            'pronto' => $pronto,
            'categorias' => $pronto ? (new \Cardapim\Services\Ifood\IfoodPublicacao($this->db, (int) $this->restaurante['id']))->painel() : [],
            'pageStyles' => ['css/ifood.css'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ]);
    }

    public function ifoodPublicarEnviar(string $slug): Response
    {
        $ids = is_array($_POST['produtos'] ?? null) ? array_values($_POST['produtos']) : [];
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->publicarCardapio($ids), 'integracoes/ifood/publicar');
    }

    public function ifoodPublicarSincronizar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->sincronizarPublicacao(), 'integracoes/ifood/publicar');
    }

    /* ---------- Loja no iFood (Merchant): dados, pausas e horarios ---------- */

    public function ifoodMinhaLoja(string $slug): Response
    {
        // Compatibilidade com links antigos: a mesma pagina unica, sem painel duplicado.
        return $this->ifood($slug);
    }

    /** Cria pausa. Pela tela (fetch) devolve o card de pausas pronto, com o aviso dentro dele; sem JS, volta para a pagina. */
    public function ifoodPausaCriar(string $slug): Response
    {
        $porJs = self::pedidoPorJs();
        if ($r = $this->entrar($slug, !$porJs)) {
            return $porJs ? BotaoLojaIfood::semSessao() : $r;
        }
        if ($porJs && !\Cardapim\Core\Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 419);
        }
        $valores = ['descricao' => $this->post('descricao'), 'inicio' => $this->post('inicio'), 'fim' => $this->post('fim')];
        $servico = $this->ifoodLojaServico();
        $res = $servico->criarPausa($valores['descricao'], $valores['inicio'], $valores['fim']);
        BotaoLojaIfood::esquecer($this->restaurante['id']);
        if ($porJs) {
            return $this->cardPausas($servico, $res, $res->ok ? IfoodLojaService::novaPausa($servico->fusoDaLoja()) : $valores);
        }
        if ($res->ok || $res->erros === []) {
            return $this->responder($res, $this->url('integracoes/ifood/minha-loja#pausas'));
        }
        return $this->telaLojaIfood(['pausa' => $res->erros + ['_geral' => $res->mensagem]], ['pausa' => $valores], 422);
    }

    /** Consulta apenas a pausa desta loja e a disponibilidade que o iFood confirmou. */
    public function ifoodPausaEstado(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        $id = (string) ($_GET['id'] ?? '');
        return \Cardapim\Services\Auth\Guard::semCache(
            Response::json($this->ifoodLojaServico()->consultarConfirmacaoPausa($id))
        );
    }

    public function ifoodPausaRemover(string $slug): Response
    {
        $porJs = self::pedidoPorJs();
        if (!$porJs) {
            return $this->acao($slug, function (): Resultado {
                BotaoLojaIfood::esquecer($this->restaurante['id']);
                return $this->ifoodLojaServico()->removerPausa($this->post('pausa'));
            }, 'integracoes/ifood/minha-loja#pausas');
        }
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        if (!\Cardapim\Core\Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 419);
        }
        $servico = $this->ifoodLojaServico();
        $res = $servico->removerPausa($this->post('pausa'));
        BotaoLojaIfood::esquecer($this->restaurante['id']);
        return $this->cardPausas($servico, $res, IfoodLojaService::novaPausa($servico->fusoDaLoja()));
    }

    /** Horario no iFood: o do Perfil (padrao) ou um horario diferente so para o iFood. */
    public function ifoodHorarios(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (($_POST['horario_proprio'] ?? '') !== '1') {
            return $this->responder($this->ifoodLojaServico()->usarHorarioDoPerfil(), $this->url('integracoes/ifood/minha-loja#horarios'));
        }
        $entrada = is_array($_POST['h'] ?? null) ? $_POST['h'] : [];
        $res = $this->ifoodLojaServico()->salvarHorarios($entrada);
        if ($res->ok || $res->erros === []) {
            return $this->responder($res, $this->url('integracoes/ifood/minha-loja#horarios'));
        }
        return $this->telaLojaIfood(['horarios' => $res->erros + ['_geral' => $res->mensagem]], ['horarios' => $entrada, 'horario_proprio' => true], 422);
    }

    /** Ajuste por restaurante e merchant do fuso operacional de interrupcoes (homologacao). */
    public function ifoodFuso(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder(
            $this->ifoodLojaServico()->definirFusoInterrupcoes($this->post('fuso')),
            $this->url('integracoes/ifood#diagnosticos')
        );
    }

    /** Botao "Abrir loja no iFood" do topo: situacao (JSON). */
    public function ifoodBotaoSituacao(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        return BotaoLojaIfood::situacao($this->db, $this->usuario, $this->restaurante['id']);
    }

    /** Botao "Abrir loja no iFood" do topo: abre a loja (JSON, X-CSRF-Token). */
    public function ifoodBotaoAbrir(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        return BotaoLojaIfood::abrir($this->db, $this->usuario, $this->restaurante['id']);
    }

    /* ---------- Internos ---------- */

    /** Envio feito pelo JS da tela (fetch com X-Requested-With: fetch). */
    private static function pedidoPorJs(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }

    /** JSON com o card de pausas atualizado e o aviso dentro dele. @param array<string,string> $valores */
    private function cardPausas(IfoodLojaService $servico, Resultado $res, array $valores): Response
    {
        $lista = $servico->pausasAgora();
        $pendente = $res->ok && ($lista['erro'] !== null || !$servico->alteracaoConfirmada($lista['pausas']));
        $html = \Cardapim\Core\View::render('gerencia/integracoes/_ifood-pausas', [
            'pausas' => $lista['pausas'],
            'erroLista' => $lista['erro'],
            'eP' => $res->ok ? [] : $res->erros,
            'vP' => $valores,
            'fusoLoja' => $servico->fusoDaLoja(),
            'nota' => [
                'tipo' => $res->ok ? ($pendente ? 'pendente' : 'sucesso') : 'erro',
                'mensagem' => $pendente
                    ? $res->mensagem . ' Ainda não foi possível confirmar a alteração na consulta ao iFood.'
                    : ($res->ok || $res->erros === [] ? $res->mensagem : 'Confira os campos destacados.'),
            ],
            'base' => $this->url(),
            'csrf' => \Cardapim\Core\Session::tokenCsrf(),
        ], 'layouts/fragmento');
        return \Cardapim\Services\Auth\Guard::semCache(Response::json([
            'ok' => $res->ok,
            'mensagem' => $res->mensagem,
            'html' => $html,
            'pausa_id' => $res->ok ? $servico->ultimaCriadaId() : null,
        ], $res->ok ? 200 : 422));
    }

    /** @param array<string,array<string,string>> $erros @param array<string,mixed> $valores */
    private function telaLojaIfood(array $erros, array $valores, int $status = 200): Response
    {
        return $this->telaIfood($erros, $valores, $status);
    }

    private function ifoodLojaServico(): IfoodLojaService
    {
        return new IfoodLojaService($this->db, $this->usuario, $this->restaurante['id']);
    }


    /** POST simples: CSRF, executa e volta com o aviso. @param callable():Resultado $executar */
    private function acao(string $slug, callable $executar, string $voltarPara): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar($this->url($voltarPara), 'erro', 'A criptografia do servidor não está disponível. Avise o suporte.');
        }
        return $this->responder($executar(), $this->url($voltarPara));
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaAsaas(array $erros, array $valores, int $status = 200): Response
    {
        $repo = new IntegracaoRepository($this->db);
        $integracao = $repo->buscar($this->restaurante['id'], 'asaas');
        return $this->tela('gerencia/integracoes/asaas', 'integracoes', 'Asaas', [
            'integracao' => $integracao,
            'urlWebhook' => $integracao['webhook_id'] !== null ? BaseIntegracao::urlPublica('/webhooks/asaas/' . $integracao['webhook_id']) : null,
            'eventos' => $repo->ultimosEventos($this->restaurante['id'], 'asaas'),
            'erros' => $erros,
            'valores' => $valores + ['ambiente' => $integracao['ambiente'] ?? 'producao', 'email_avisos' => (string) ($integracao['dados']['email_avisos'] ?? '')],
            'tokenWebhook' => $integracao['status'] !== 'desconectado' && empty($integracao['dados']['webhook_asaas_id'])
                ? (string) ($repo->credenciais($this->restaurante['id'], 'asaas')['token_webhook'] ?? '') : null,
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    private function asaasServico(): AsaasService
    {
        return new AsaasService($this->db, $this->usuario, $this->restaurante['id']);
    }

    private function whatsappServico(): WhatsAppService
    {
        return new WhatsAppService($this->db, $this->usuario, $this->restaurante['id']);
    }

    private function ifoodServico(): IfoodService
    {
        return new IfoodService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
