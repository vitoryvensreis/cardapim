<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Modules\Integracoes\AplicativosParceiros;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\Integracoes\AsaasService;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Services\Gerencia\Integracoes\WhatsAppService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Plataforma\AplicativosParceirosService;
use Cardapim\Services\Plataforma\CobrancaAssinaturas;
use Cardapim\Services\Plataforma\SmsGatewayService;
use Cardapim\Services\Plataforma\GoogleMapsService;
use Cardapim\Shared\Seguranca\Cripto;

/**
 * Integracoes da PLATAFORMA (/painel/integracoes): contas da propria administracao do Cardapim.
 * - Asaas: recebe o pagamento das assinaturas dos restaurantes.
 * - WhatsApp: numero da plataforma (codigos de verificacao do cliente final), separado dos restaurantes.
 * - SMS: servidor SMSGate (celular Android), reserva automatica dos codigos quando o WhatsApp falha.
 * - Aplicativos (iFood, 99Food): Client ID / Client Secret do app do Cardapim em cada marketplace.
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
            'whatsappDisponivel' => WhatsAppProvedores::algumDisponivel(),
            'sms' => $this->smsServico()->resumo(),
            'google' => $this->googleServico()->estado(),
            'aplicativos' => array_map(
                fn (string $provedor): array => $this->aplicativosServico()->resumo($provedor),
                array_keys(AplicativosParceiros::PROVEDORES),
            ),
        ]);
    }

    /* ---------- Aplicativos nos marketplaces (iFood, 99Food) ---------- */

    public function aplicativo(string $provedor): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        if (!AplicativosParceiros::existe($provedor)) {
            return $this->voltar('/painel/integracoes', 'erro', 'Aplicativo não encontrado.');
        }
        return $this->telaAplicativo($provedor, [], []);
    }

    public function aplicativoSalvar(string $provedor): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        if (!AplicativosParceiros::existe($provedor)) {
            return $this->voltar('/painel/integracoes', 'erro', 'Aplicativo não encontrado.');
        }
        if (!Cripto::disponivel()) {
            return $this->voltar('/painel/integracoes/aplicativos/' . $provedor, 'erro', 'A criptografia do servidor não está disponível.');
        }
        $resultado = $this->aplicativosServico()->salvar($provedor, $this->post('client_id'), (string) ($_POST['client_secret'] ?? ''));
        if ($resultado->ok) {
            return $this->voltar('/painel/integracoes/aplicativos/' . $provedor, 'sucesso', $resultado->mensagem);
        }
        return $this->telaAplicativo($provedor, $resultado->erros + ['_geral' => $resultado->mensagem], ['client_id' => $this->post('client_id')], 422);
    }

    public function aplicativoRemover(string $provedor): Response
    {
        if (!AplicativosParceiros::existe($provedor)) {
            return $this->voltar('/painel/integracoes', 'erro', 'Aplicativo não encontrado.');
        }
        return $this->acao(fn (): Resultado => $this->aplicativosServico()->remover($provedor), '/painel/integracoes/aplicativos/' . $provedor);
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

    public function asaasReativar(): Response
    {
        return $this->acao(fn (): Resultado => $this->asaasServico()->reativarAvisos(), '/painel/integracoes/asaas');
    }

    /** Regras de cobranca das assinaturas (formas, vencimento, multa, juros, desconto, tolerancia, avisos do Asaas). */
    public function asaasCobranca(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $res = (new CobrancaAssinaturas($this->db))->salvar($_POST, $this->usuario);
        if ($res->ok) {
            return $this->voltar('/painel/integracoes/asaas#cobranca', 'sucesso', $res->mensagem);
        }
        return $this->telaAsaas([], [], 422, ['erros' => $res->erros, 'enviado' => $_POST, 'mensagem' => $res->mensagem]);
    }

    /* ---------- WhatsApp da plataforma ---------- */

    public function whatsapp(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->telaWhatsapp([], ['mensagem' => 'Olá! Esta é uma mensagem de teste do WhatsApp da plataforma ' . marca() . '.']);
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

    /* ---------- SMS da plataforma (SMSGate) ---------- */

    public function sms(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->telaSms([], []);
    }

    public function smsSalvar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar('/painel/integracoes/sms', 'erro', 'A criptografia do servidor não está disponível.');
        }
        $resultado = $this->smsServico()->salvar($this->post('url'), $this->post('usuario'), (string) ($_POST['senha'] ?? ''));
        if ($resultado->ok) {
            return $this->voltar('/painel/integracoes/sms#teste', 'sucesso', $resultado->mensagem);
        }
        return $this->telaSms($resultado->erros + ['_geral' => $resultado->mensagem], ['url' => $this->post('url'), 'usuario' => $this->post('usuario')], 422);
    }

    public function smsTeste(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $resultado = $this->smsServico()->testar($this->post('telefone'));
        if ($resultado->ok || $resultado->erros === []) {
            return $this->responder($resultado, '/painel/integracoes/sms#teste');
        }
        return $this->telaSms($resultado->erros, ['telefone' => $this->post('telefone')], 422);
    }

    public function smsRemover(): Response
    {
        return $this->acao(fn (): Resultado => $this->smsServico()->remover(), '/painel/integracoes/sms');
    }

    /* ---------- Google Routes: chave unica para a plataforma ---------- */

    public function google(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->telaGoogle([]);
    }

    public function googleSalvar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $resultado = $this->googleServico()->salvar($this->post('chave'));
        if ($resultado->ok) {
            return $this->voltar('/painel/integracoes/google-maps', 'sucesso', $resultado->mensagem);
        }
        return $this->telaGoogle($resultado->erros + ['_geral' => $resultado->mensagem], 422);
    }

    public function googleTestar(): Response
    {
        return $this->acao(fn (): Resultado => $this->googleServico()->testar(), '/painel/integracoes/google-maps');
    }

    public function googleAtivar(): Response
    {
        return $this->acao(fn (): Resultado => $this->googleServico()->ativar(true), '/painel/integracoes/google-maps');
    }

    public function googleDesativar(): Response
    {
        return $this->acao(fn (): Resultado => $this->googleServico()->ativar(false), '/painel/integracoes/google-maps');
    }

    public function googleRemover(): Response
    {
        return $this->acao(fn (): Resultado => $this->googleServico()->remover(), '/painel/integracoes/google-maps');
    }

    /** @param array<string,string> $erros */
    private function telaGoogle(array $erros, int $status = 200): Response
    {
        return $this->tela('plataforma/integracoes/google-maps', 'integracoes', 'Google Maps', [
            'google' => $this->googleServico()->estado(),
            'erros' => $erros,
            'pageScripts' => ['js/forms.js'],
        ], $status);
    }

    private function googleServico(): GoogleMapsService
    {
        return new GoogleMapsService($this->db, $this->usuario);
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

    /**
     * @param array<string,string> $erros @param array<string,string> $valores
     * @param array{erros?:array<string,string>, enviado?:array<string,mixed>, mensagem?:string} $cobrancaForm erro ao salvar as regras
     */
    private function telaAsaas(array $erros, array $valores, int $status = 200, array $cobrancaForm = []): Response
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
            'saude' => is_array($integracao['dados']['saude'] ?? null) ? $integracao['dados']['saude'] : null,
            'cobranca' => (new CobrancaAssinaturas($this->db))->regras(),
            'cobrancaForm' => $cobrancaForm,
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaWhatsapp(array $erros, array $valores, int $status = 200): Response
    {
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'plataforma' => true,
            'integracao' => (new IntegracaoRepository($this->db))->buscar(null, 'whatsapp'),
            'disponivel' => WhatsAppProvedores::algumDisponivel(),
            'erros' => $erros,
            'valores' => $valores,
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaAplicativo(string $provedor, array $erros, array $valores, int $status = 200): Response
    {
        $app = $this->aplicativosServico()->resumo($provedor);
        return $this->tela('plataforma/integracoes/aplicativo', 'integracoes', $app['nome'], [
            'app' => $app,
            'erros' => $erros,
            'valores' => $valores + ['client_id' => $app['client_id']],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaSms(array $erros, array $valores, int $status = 200): Response
    {
        $sms = $this->smsServico()->resumo();
        return $this->tela('plataforma/integracoes/sms', 'integracoes', 'SMS', [
            'sms' => $sms,
            'erros' => $erros,
            'valores' => $valores + ['url' => $sms['origem'] === 'painel' ? $sms['url'] : 'https://sms.escotilhaprodutora.com', 'usuario' => $sms['usuario']],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    private function smsServico(): SmsGatewayService
    {
        return new SmsGatewayService($this->db, $this->usuario);
    }

    private function aplicativosServico(): AplicativosParceirosService
    {
        return new AplicativosParceirosService($this->db, $this->usuario);
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
