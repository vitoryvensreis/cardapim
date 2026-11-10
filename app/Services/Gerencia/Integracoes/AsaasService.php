<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;

/** Asaas: cada restaurante conecta a PROPRIA conta para receber Pix dos pedidos online. */
final class AsaasService extends BaseIntegracao
{
    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly AsaasApi $api = new AsaasApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    public function conectar(string $ambiente, string $chave, string $emailAvisos): Resultado
    {
        $chave = trim($chave);
        $erros = [];
        if (!in_array($ambiente, ['producao', 'sandbox'], true)) {
            $erros['ambiente'] = 'Escolha se é a conta real (Produção) ou de testes (Sandbox).';
        }
        if (strlen($chave) < 20 || strlen($chave) > 400 || preg_match('/\s/', $chave) === 1) {
            $erros['chave'] = 'Cole a chave de API completa, sem espaços. No Asaas: Integrações > Chaves de API.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        try {
            $saldo = $this->api->saldo($chave, $ambiente);
            $conta = $this->api->conta($chave, $ambiente);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage(), ['chave' => $erro->getMessage()]);
        }

        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $antigas = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        $this->removerWebhookAntigo($antigas, $atual);
        $this->integracoes->salvar($this->idRestaurante, 'asaas', [
            'status' => 'conectado',
            'ambiente' => $ambiente,
            'credenciais' => ['chave' => $chave, 'token_webhook' => bin2hex(random_bytes(24))],
            'dados' => ['conta_nome' => $conta['nome'], 'conta_email' => $conta['email'], 'chave_final' => self::finalDaChave($chave), 'email_avisos' => $emailAvisos],
            'webhook_id' => bin2hex(random_bytes(16)),
            'ultimo_erro' => null,
            'conectado_em' => self::agora(),
            'verificado_em' => self::agora(),
        ]);
        $this->log('Integração Asaas conectada', sprintf('Ambiente: %s. Conta: %s. Chave %s.', $ambiente === 'producao' ? 'Produção' : 'Sandbox', $conta['nome'] ?: 'sem nome informado', self::finalDaChave($chave)));

        $webhook = $this->configurarWebhook();
        return Resultado::sucesso('Conta Asaas conectada' . ($conta['nome'] !== '' ? ' (' . $conta['nome'] . ')' : '') . '. Saldo atual: ' . Numero::reais($saldo) . '. '
            . ($webhook->ok ? 'Os avisos de pagamento já estão configurados.' : 'Falta configurar os avisos de pagamento: veja abaixo.'));
    }

    /** Cria no Asaas o webhook que avisa o Cardapim quando um pagamento muda. */
    public function configurarWebhook(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        if ($atual['status'] !== 'conectado' || empty($cred['chave']) || empty($cred['token_webhook']) || $atual['webhook_id'] === null) {
            return Resultado::falha('Conecte a conta Asaas primeiro.');
        }
        $url = self::urlPublica('/webhooks/asaas/' . $atual['webhook_id']);
        if (!str_starts_with($url, 'https://')) {
            return Resultado::falha('O endereço do Cardapim precisa ser HTTPS para receber avisos do Asaas.');
        }
        $email = filter_var($atual['dados']['email_avisos'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $atual['dados']['email_avisos'] : (string) ($atual['dados']['conta_email'] ?? '');
        try {
            $this->removerWebhookAntigo($cred, $atual);
            $id = $this->api->criarWebhook((string) $cred['chave'], (string) $atual['ambiente'], $url, (string) $cred['token_webhook'], $email);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'asaas', ['ultimo_erro' => $erro->getMessage(), 'dados' => [...$atual['dados'], 'webhook_asaas_id' => null]]);
            return Resultado::falha('Não foi possível configurar os avisos automaticamente. ' . $erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'asaas', [
            'ultimo_erro' => null,
            'dados' => [...$atual['dados'], 'webhook_asaas_id' => $id, 'webhook_configurado_em' => self::agora()],
        ]);
        $this->log('Integração Asaas: avisos configurados', 'Webhook criado no Asaas para os eventos de pagamento.');
        return Resultado::sucesso('Pronto! O Asaas vai avisar o Cardapim sempre que um pagamento mudar.');
    }

    public function testar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'asaas');
        if (empty($cred['chave'])) {
            return Resultado::falha('Conecte a conta Asaas primeiro.');
        }
        try {
            $saldo = $this->api->saldo((string) $cred['chave'], (string) $atual['ambiente']);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'asaas', ['status' => 'erro', 'ultimo_erro' => $erro->getMessage()]);
            return Resultado::falha($erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'asaas', ['status' => 'conectado', 'ultimo_erro' => null, 'verificado_em' => self::agora()]);
        return Resultado::sucesso('Conexão com o Asaas funcionando. Saldo atual: ' . Numero::reais($saldo) . '.');
    }

    public function desconectar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'asaas');
        if ($atual['status'] === 'desconectado') {
            return Resultado::falha('O Asaas já está desconectado.');
        }
        $this->removerWebhookAntigo($this->integracoes->credenciais($this->idRestaurante, 'asaas'), $atual);
        $this->integracoes->limpar($this->idRestaurante, 'asaas');
        $this->log('Integração Asaas desconectada', 'Chave de API e token dos avisos apagados do Cardapim.');
        return Resultado::sucesso('Asaas desconectado. A chave foi apagada do Cardapim.');
    }

    /** @param array<string,mixed> $cred @param array<string,mixed> $atual */
    private function removerWebhookAntigo(array $cred, array $atual): void
    {
        $id = $atual['dados']['webhook_asaas_id'] ?? null;
        if (!is_string($id) || $id === '' || empty($cred['chave']) || empty($atual['ambiente'])) {
            return;
        }
        try {
            $this->api->removerWebhook((string) $cred['chave'], (string) $atual['ambiente'], $id);
        } catch (IntegracaoErro) {
            // Se o Asaas nao responder, o webhook antigo continua la, mas o token dele deixa de valer aqui.
        }
    }
}
