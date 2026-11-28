<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * WAHA (WhatsApp HTTP API, motor GOWS/whatsmeow) na VPS de mensageria do Deliveryzim (docs/deploy/waha.md).
 * Uma sessao por restaurante, com o mesmo nome do padrao (deliveryzim_r{id}_...).
 *
 * Chaves:
 * - WAHA_API_KEY (so no .env do servidor): todas as chamadas, no cabecalho X-Api-Key.
 * - token da sessao (gerado aqui ao criar, guardado cifrado no banco): chave HMAC do webhook daquela loja.
 *   O webhook confere a assinatura (X-Webhook-Hmac, HMAC-SHA512 do corpo) com o token guardado da loja.
 * O webhook de cada sessao de loja aponta para /webhooks/waha/{WAHA_WEBHOOK_TOKEN} (evento "message":
 * so mensagens recebidas). A sessao da plataforma (codigos de acesso) nao tem webhook.
 */
class WahaApi implements ProvedorWhatsApp, WebhookDeMensagens
{
    /** Status do WAHA -> status unico dos servicos. PASSKEY_*: o WhatsApp pediu uma etapa a mais no celular. */
    private const STATUS = [
        'WORKING' => 'CONNECTED',
        'SCAN_QR_CODE' => 'QRCODE',
        'STARTING' => 'INITIALIZING',
        'PASSKEY_REQUIRED' => 'INITIALIZING',
        'PASSKEY_CONFIRMATION_REQUIRED' => 'INITIALIZING',
    ];

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(20))
    {
    }

    public function id(): string
    {
        return WhatsAppProvedores::WAHA;
    }

    public function configurado(): bool
    {
        return self::urlBase() !== '' && Config::get('WAHA_API_KEY') !== '';
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('WAHA_URL'), '/');
    }

    /** Endereco que o WAHA chama quando chega mensagem (vazio = webhook desligado). */
    public static function urlWebhook(): string
    {
        $segredo = Config::get('WAHA_WEBHOOK_TOKEN');
        return strlen($segredo) >= 32 ? BaseIntegracao::urlPublica('/webhooks/waha/' . rawurlencode($segredo)) : '';
    }

    public function webhookConfigurado(): bool
    {
        return self::urlWebhook() !== '';
    }

    public function criarSessao(string $sessao): string
    {
        $token = bin2hex(random_bytes(32));
        $r = $this->chamar('POST', '/api/sessions', [
            'name' => WhatsAppProvedores::sessao($sessao),
            'start' => false,
            'config' => self::configuracao($sessao, $token),
        ]);
        if (!$r->ok()) {
            throw new IntegracaoErro($r->status === 401 || $r->status === 403
                ? 'O servidor do WhatsApp recusou a conexão. Avise o suporte do ' . marca() . ' (chave do servidor incorreta).'
                : 'Não foi possível preparar o WhatsApp agora (código ' . $r->status . '). Tente de novo em instantes.');
        }
        return $token;
    }

    public function iniciar(string $sessao, string $token): void
    {
        $r = $this->chamar('POST', '/api/sessions/' . WhatsAppProvedores::sessao($sessao) . '/start');
        if (!$r->ok()) {
            throw new IntegracaoErro('Não foi possível iniciar o WhatsApp agora (código ' . $r->status . '). Tente de novo em instantes.');
        }
    }

    public function estado(string $sessao, string $token): array
    {
        $nome = WhatsAppProvedores::sessao($sessao);
        $r = $this->chamar('GET', '/api/sessions/' . $nome);
        $status = self::STATUS[strtoupper((string) ($r->json()['status'] ?? ''))] ?? null;
        if (!$r->ok() || $status === null) {
            return ['status' => 'CLOSED', 'qrcode' => null]; // STOPPED, FAILED, 404 ou resposta estranha
        }
        if ($status !== 'QRCODE') {
            return ['status' => $status, 'qrcode' => null];
        }
        // Aguardando leitura: QR atual em base64 (Accept: application/json).
        $qr = $this->chamar('GET', '/api/' . $nome . '/auth/qr')->json();
        $dados = $qr['data'] ?? null;
        return is_string($dados) && $dados !== '' && preg_match('~\A[A-Za-z0-9+/=]+\z~', $dados) === 1
            ? ['status' => 'QRCODE', 'qrcode' => 'data:' . (is_string($qr['mimetype'] ?? null) ? $qr['mimetype'] : 'image/png') . ';base64,' . $dados]
            : ['status' => 'INITIALIZING', 'qrcode' => null];
    }

    public function numero(string $sessao, string $token): ?string
    {
        try {
            $me = $this->chamar('GET', '/api/sessions/' . WhatsAppProvedores::sessao($sessao))->json()['me'] ?? null;
        } catch (IntegracaoErro) {
            return null;
        }
        $id = is_array($me) ? ($me['id'] ?? null) : null;
        $digitos = is_string($id) ? (preg_replace('/\D+/', '', explode('@', $id)[0]) ?? '') : '';
        return strlen($digitos) >= 10 && strlen($digitos) <= 15 ? $digitos : null;
    }

    public function enviarTexto(string $sessao, string $token, string $telefone, string $mensagem): void
    {
        $nome = WhatsAppProvedores::sessao($sessao);
        $chat = preg_match('/\A[0-9]{6,24}@lid\z/D', $telefone) === 1 ? $telefone : $this->conversa($nome, $telefone);
        $r = $this->chamar('POST', '/api/sendText', ['session' => $nome, 'chatId' => $chat, 'text' => $mensagem]);
        if (!$r->ok()) {
            throw new IntegracaoErro('O WhatsApp não conseguiu enviar a mensagem (código ' . $r->status . '). Confira se o número tem WhatsApp e se o celular está conectado.');
        }
    }

    /**
     * Conversa certa do numero (no Brasil o WhatsApp pode guardar o numero sem o 9 extra). Sem resposta do servidor,
     * usa o numero como veio.
     */
    private function conversa(string $sessao, string $telefone): string
    {
        $digitos = preg_replace('/\D+/', '', $telefone) ?? '';
        if (strlen($digitos) < 10 || strlen($digitos) > 15) {
            throw new IntegracaoErro('Número de WhatsApp inválido.');
        }
        try {
            $r = $this->chamar('GET', '/api/contacts/check-exists?' . http_build_query(['phone' => $digitos, 'session' => $sessao]));
        } catch (IntegracaoErro) {
            return $digitos . '@c.us';
        }
        $d = $r->json();
        if ($r->ok() && ($d['numberExists'] ?? null) === false) {
            throw new IntegracaoErro('Este número não tem WhatsApp.');
        }
        $chat = $d['chatId'] ?? null;
        return $r->ok() && is_string($chat) && preg_match('/\A[0-9]{6,24}@(c\.us|lid)\z/D', $chat) === 1 ? $chat : $digitos . '@c.us';
    }

    /**
     * Webhook gravado na sessao: 'ok' (aponta para o Deliveryzim, evento de mensagem e assinatura), 'errado' ou
     * 'desconhecido' (servidor nao respondeu).
     */
    public function situacaoWebhook(string $sessao, string $token): string
    {
        $esperado = self::urlWebhook();
        try {
            $r = $this->chamar('GET', '/api/sessions/' . WhatsAppProvedores::sessao($sessao));
        } catch (IntegracaoErro) {
            return 'desconhecido';
        }
        if (!$r->ok()) {
            return $r->status === 404 ? 'errado' : 'desconhecido';
        }
        $webhooks = $r->json()['config']['webhooks'] ?? null;
        foreach (is_array($webhooks) ? $webhooks : [] as $w) {
            $eventos = is_array($w) && is_array($w['events'] ?? null) ? $w['events'] : [];
            if ($esperado !== '' && ($w['url'] ?? null) === $esperado && (in_array('message', $eventos, true) || in_array('*', $eventos, true))) {
                return 'ok';
            }
        }
        return 'errado';
    }

    /** Grava de novo o webhook da sessao (o WAHA reinicia a sessao para aplicar; o aparelho continua conectado). */
    public function configurarWebhook(string $sessao, string $token): void
    {
        if (self::urlWebhook() === '') {
            throw new IntegracaoErro('O recebimento de mensagens ainda não foi configurado no servidor. Avise o suporte.');
        }
        $nome = WhatsAppProvedores::sessao($sessao);
        $r = $this->chamar('PUT', '/api/sessions/' . $nome, ['name' => $nome, 'config' => self::configuracao($nome, $token)]);
        if (!$r->ok()) {
            throw new IntegracaoErro('O servidor do WhatsApp não aceitou o ajuste agora (código ' . $r->status . '). Tente de novo em instantes.');
        }
    }

    /** Desconecta o aparelho e apaga a sessao. Erros sao ignorados (a limpeza automatica fecha o que sobrar). */
    public function sair(string $sessao, string $token): bool
    {
        $nome = WhatsAppProvedores::sessao($sessao);
        $confirmou = false;
        try {
            $confirmou = $this->chamar('POST', '/api/sessions/' . $nome . '/logout')->ok();
        } catch (IntegracaoErro) {
            // segue para apagar
        }
        return $this->apagar($nome) || $confirmou;
    }

    public function sessoesNoServidor(): array
    {
        $r = $this->chamar('GET', '/api/sessions?all=true');
        $lista = $r->json();
        if (!$r->ok() || !array_is_list($lista)) {
            throw new IntegracaoErro('O servidor do WhatsApp não listou as sessões (código ' . $r->status . ').');
        }
        $nomes = [];
        foreach ($lista as $item) {
            $nome = is_array($item) ? ($item['name'] ?? null) : null;
            if (is_string($nome) && preg_match('/\A[a-zA-Z0-9_-]{3,60}\z/', $nome) === 1) {
                $nomes[] = $nome;
            }
        }
        return $nomes;
    }

    public function encerrar(string $sessao): bool
    {
        return $this->apagar(WhatsAppProvedores::sessao($sessao));
    }

    /**
     * Configuracao da sessao: webhook so para lojas (a da plataforma so envia codigos), assinado com o token da loja.
     * @return array<string,mixed>
     */
    public static function configuracao(string $sessao, string $token): array
    {
        $url = self::urlWebhook();
        if ($url === '' || WhatsAppProvedores::restauranteDaSessao($sessao) === null) {
            return ['webhooks' => []];
        }
        return ['webhooks' => [[
            'url' => $url,
            'events' => ['message'],
            'hmac' => ['key' => $token],
            // Deliveryzim fora do ar por instantes: tenta de novo (2 s, 4 s, 8 s...). Mensagem antiga e ignorada pela saudacao.
            'retries' => ['policy' => 'exponential', 'delaySeconds' => 2, 'attempts' => 4],
        ]]];
    }

    /** Assinatura do webhook: HMAC-SHA512 do corpo exatamente como chegou, com o token da sessao. */
    public static function assinaturaValida(string $corpo, string $token, ?string $recebida): bool
    {
        if ($token === '' || !is_string($recebida) || $recebida === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $corpo, $token), strtolower(trim($recebida)));
    }

    private function apagar(string $nome): bool
    {
        try {
            $r = $this->chamar('DELETE', '/api/sessions/' . $nome);
        } catch (IntegracaoErro) {
            return false;
        }
        return $r->ok() || $r->status === 404;
    }

    /** @param array<mixed>|null $corpo */
    private function chamar(string $metodo, string $caminho, ?array $corpo = null): RespostaHttp
    {
        if (!$this->configurado()) {
            throw new IntegracaoErro('O WhatsApp ainda não está disponível neste servidor. Avise o suporte do ' . marca() . '.');
        }
        try {
            return $this->http->enviar($metodo, self::urlBase() . $caminho, ['X-Api-Key' => Config::get('WAHA_API_KEY')], $corpo);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('O servidor do WhatsApp não respondeu: ' . $erro->getMessage());
        }
    }
}
