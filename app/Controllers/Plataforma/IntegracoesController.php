<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\Integracoes\AsaasService;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Services\Gerencia\Integracoes\WhatsAppService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Seguranca\Cripto;

/**
 * Integracoes da PLATAFORMA (/painel/integracoes): contas da propria administracao do Cardapim.
 * - Asaas: recebe o pagamento das assinaturas dos restaurantes.
 * - WhatsApp: numero da plataforma (codigos de verificacao do cliente final), separado dos restaurantes.
 * Mesmas regras e servicos das integracoes dos restaurantes, com escopo "plataforma" (idRestaurante null).
 */
final class IntegracoesController extends BasePlataforma
{
    public function inicio(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new IntegracaoRepository($this->db);
        return $this->tela('plataforma/integracoes/inicio', 'integracoes', 'Integrações', [
            'asaas' => $repo->buscar(null, 'asaas'),
            'whatsapp' => $repo->buscar(null, 'whatsapp'),
            'whatsappDisponivel' => WppConnectApi::disponivel(),
        ]);
    }

    /* ---------- Asaas (assinaturas) ---------- */

    public function asaas(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->telaAsaas([], []);
    }

    public function asaasConectar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar('/painel/integracoes/asaas', 'erro', 'A criptografia do servidor não está disponível.');
        }
        $resultado = $this->asaasServico()->conectar($this->post('ambiente'), (string) ($_POST['chave'] ?? ''), $this->post('email_avisos'));
        if ($resultado->ok) {
            return $this->voltar('/painel/integracoes/asaas', 'sucesso', $resultado->mensagem);
        }
        return $this->telaAsaas($resultado->erros + ['_geral' => $resultado->mensagem], ['ambiente' => $this->post('ambiente'), 'email_avisos' => $this->post('email_avisos')], 422);
    }

    public function asaasWebhook(): Response
    {
        return $this->acao(fn (): Resultado => $this->asaasServico()->configurarWebhook(), '/painel/integracoes/asaas');
    }

    public function asaasTestar(): Response
    {
        return $this->acao(fn (): Resultado => $this->asaasServico()->testar(), '/painel/integracoes/asaas');
    }

    public function asaasDesconectar(): Response
    {
        return $this->acao(fn (): Resultado => $this->asaasServico()->desconectar(), '/painel/integracoes/asaas');
    }

    /* ---------- WhatsApp da plataforma ---------- */

    public function whatsapp(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->telaWhatsapp([], ['mensagem' => 'Olá! Esta é uma mensagem de teste do WhatsApp da plataforma Cardapim.']);
    }

    public function whatsappConectar(): Response
    {
        return $this->acao(fn (): Resultado => $this->whatsappServico()->conectar(), '/painel/integracoes/whatsapp');
    }

    /** JSON consultado pela tela enquanto o QR Code esta aberto. */
    public function whatsappEstado(): Response
    {
        if ($r = $this->entrar()) {
            return Response::json(['estado' => 'sessao', 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 401);
        }
        return Guard::semCache(Response::json($this->whatsappServico()->estado()));
    }

    public function whatsappTeste(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $resultado = $this->whatsappServico()->enviarTeste($this->post('telefone'), (string) ($_POST['mensagem'] ?? ''));
        if ($resultado->ok || $resultado->erros === []) {
            return $this->responder($resultado, '/painel/integracoes/whatsapp#teste');
        }
        return $this->telaWhatsapp($resultado->erros, ['telefone' => $this->post('telefone'), 'mensagem' => (string) ($_POST['mensagem'] ?? '')], 422);
    }

    public function whatsappDesconectar(): Response
    {
        return $this->acao(fn (): Resultado => $this->whatsappServico()->desconectar(), '/painel/integracoes/whatsapp');
    }

    /* ---------- Internos ---------- */

    /** @param callable():Resultado $executar */
    private function acao(callable $executar, string $voltarPara): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar($voltarPara, 'erro', 'A criptografia do servidor não está disponível.');
        }
        return $this->responder($executar(), $voltarPara);
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaAsaas(array $erros, array $valores, int $status = 200): Response
    {
        $repo = new IntegracaoRepository($this->db);
        $integracao = $repo->buscar(null, 'asaas');
        return $this->tela('gerencia/integracoes/asaas', 'integracoes', 'Asaas', [
            'plataforma' => true,
            'integracao' => $integracao,
            'urlWebhook' => $integracao['webhook_id'] !== null ? BaseIntegracao::urlPublica('/webhooks/asaas/' . $integracao['webhook_id']) : null,
            'eventos' => $repo->ultimosEventos(null, 'asaas'),
            'erros' => $erros,
            'valores' => $valores + ['ambiente' => $integracao['ambiente'] ?? 'producao', 'email_avisos' => (string) ($integracao['dados']['email_avisos'] ?? '')],
            'tokenWebhook' => $integracao['status'] !== 'desconectado' && empty($integracao['dados']['webhook_asaas_id'])
                ? (string) ($repo->credenciais(null, 'asaas')['token_webhook'] ?? '') : null,
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaWhatsapp(array $erros, array $valores, int $status = 200): Response
    {
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'plataforma' => true,
            'integracao' => (new IntegracaoRepository($this->db))->buscar(null, 'whatsapp'),
            'disponivel' => WppConnectApi::disponivel(),
            'erros' => $erros,
            'valores' => $valores,
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    private function asaasServico(): AsaasService
    {
        return new AsaasService($this->db, $this->usuario, null);
    }

    private function whatsappServico(): WhatsAppService
    {
        return new WhatsAppService($this->db, $this->usuario, null);
    }
}
