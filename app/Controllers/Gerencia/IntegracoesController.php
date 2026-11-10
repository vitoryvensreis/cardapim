<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\Integracoes\AsaasService;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Services\Gerencia\Integracoes\IfoodService;
use Cardapim\Services\Gerencia\Integracoes\WhatsAppService;
use Cardapim\Services\Gerencia\Resultado;
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
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'integracao' => $integracao,
            'disponivel' => WppConnectApi::disponivel(),
            'erros' => [],
            'valores' => ['mensagem' => 'Olá! Esta é uma mensagem de teste do ' . $this->restaurante['nome'] . ' pelo Cardapim.'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ]);
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
        ], 422);
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
        return $this->tela('gerencia/integracoes/ifood', 'integracoes', 'iFood', [
            'integracao' => (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'ifood'),
            'disponivel' => IfoodApi::disponivel(),
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
