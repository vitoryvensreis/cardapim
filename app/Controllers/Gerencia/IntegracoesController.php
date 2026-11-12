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
use Cardapim\Services\Gerencia\Integracoes\IfoodService;
use Cardapim\Services\Gerencia\Integracoes\WhatsAppService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\IfoodCatalogo;
use Cardapim\Services\Ifood\IfoodConta;
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
            'pageScripts' => ['js/integracoes.js'],
        ]);
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

    /* ---------- Internos ---------- */

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
