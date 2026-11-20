<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * Evolution API (v2, canal Baileys) na VPS de mensageria do Deliveryzim.
 * Uma instancia por restaurante, com o mesmo nome da sessao (deliveryzim_r{id}_...).
 *
 * Chaves:
 * - EVOLUTION_API_KEY (chave global, so no .env do servidor): cria, lista e apaga instancias.
 * - token da instancia (campo "hash" devolvido ao criar, guardado cifrado no banco): QR Code, estado,
 *   envio e logout daquela loja.
 * O webhook de cada instancia aponta para /webhooks/evolution/{EVOLUTION_WEBHOOK_TOKEN}.
 */
class EvolutionApi implements ProvedorWhatsApp
{
    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(20))
    {
    }

    public function id(): string
    {
        return WhatsAppProvedores::EVOLUTION;
    }

    public function configurado(): bool
    {
        return self::urlBase() !== '' && Config::get('EVOLUTION_API_KEY') !== '';
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('EVOLUTION_BASE_URL'), '/');
    }

    /** Endereco que a Evolution chama quando chega mensagem (vazio = webhook desligado). */
    public static function urlWebhook(): string
    {
        $segredo = Config::get('EVOLUTION_WEBHOOK_TOKEN');
        return strlen($segredo) >= 32 ? BaseIntegracao::urlPublica('/webhooks/evolution/' . rawurlencode($segredo)) : '';
    }

    public function criarSessao(string $sessao): string
    {
        $corpo = [
            'instanceName' => WhatsAppProvedores::sessao($sessao),
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
            // Mesmos ajustes para todas as lojas: sem grupos, sem historico, celular continua recebendo notificacoes.
            'groupsIgnore' => true,
            'rejectCall' => false,
            'alwaysOnline' => false,
            'readMessages' => false,
            'readStatus' => false,
            'syncFullHistory' => false,
        ];
        $webhook = self::urlWebhook();
        if ($webhook !== '') {
            $corpo['webhook'] = ['url' => $webhook, 'byEvents' => false, 'base64' => false, 'events' => ['MESSAGES_UPSERT']];
        }
        $r = $this->chamar('POST', '/instance/create', $this->chaveGlobal(), $corpo);
        $d = $r->json();
        $hash = $d['hash'] ?? null;
        $token = is_string($hash) ? $hash : (is_array($hash) && is_string($hash['apikey'] ?? null) ? $hash['apikey'] : null);
        if (!$r->ok() || $token === null || $token === '') {
            throw new IntegracaoErro($r->status === 401 || $r->status === 403
                ? 'O servidor do WhatsApp recusou a conexão. Avise o suporte do ' . marca() . ' (chave do servidor incorreta).'
                : 'Não foi possível preparar o WhatsApp agora (código ' . $r->status . '). Tente de novo em instantes.');
        }
        return $token;
    }

    public function iniciar(string $sessao, string $token): void
    {
        $r = $this->chamar('GET', '/instance/connect/' . WhatsAppProvedores::sessao($sessao), $token);
        if (!$r->ok()) {
            throw new IntegracaoErro('Não foi possível iniciar o WhatsApp agora (código ' . $r->status . '). Tente de novo em instantes.');
        }
    }

    public function estado(string $sessao, string $token): array
    {
        $nome = WhatsAppProvedores::sessao($sessao);
        $r = $this->chamar('GET', '/instance/connectionState/' . $nome, $token);
        if ($r->status === 404) {
            return ['status' => 'CLOSED', 'qrcode' => null];
        }
        $d = $r->json();
        $estado = strtolower((string) ($d['instance']['state'] ?? $d['state'] ?? ''));
        if (!$r->ok() || $estado === '') {
            return ['status' => 'CLOSED', 'qrcode' => null];
        }
        if ($estado === 'open') {
            return ['status' => 'CONNECTED', 'qrcode' => null];
        }
        if ($estado !== 'connecting') {
            return ['status' => 'CLOSED', 'qrcode' => null];
        }
        // Aguardando leitura: o QR Code atual vem do /instance/connect.
        $qr = $this->chamar('GET', '/instance/connect/' . $nome, $token)->json()['base64'] ?? null;
        return is_string($qr) && str_starts_with($qr, 'data:image/')
            ? ['status' => 'QRCODE', 'qrcode' => $qr]
            : ['status' => 'INITIALIZING', 'qrcode' => null];
    }

    /** A Evolution nao usa navegador por sessao, entao nao existe o "conectado mas travado" do WPPConnect. */
    public function respondendo(string $sessao, string $token): ?bool
    {
        return null;
    }

    public function numero(string $sessao, string $token): ?string
    {
        try {
            $d = $this->chamar('GET', '/instance/fetchInstances?instanceName=' . rawurlencode(WhatsAppProvedores::sessao($sessao)), $token)->json();
        } catch (IntegracaoErro) {
            return null;
        }
        $item = array_is_list($d) ? ($d[0] ?? []) : $d;
        if (!is_array($item)) {
            return null;
        }
        foreach ([$item['ownerJid'] ?? null, $item['owner'] ?? null, $item['instance']['owner'] ?? null, $item['number'] ?? null] as $c) {
            if (is_string($c) || is_int($c)) {
                $digitos = preg_replace('/\D+/', '', explode('@', (string) $c)[0]) ?? '';
                if (strlen($digitos) >= 10 && strlen($digitos) <= 15) {
                    return $digitos;
                }
            }
        }
        return null;
    }

    public function enviarTexto(string $sessao, string $token, string $telefone, string $mensagem): void
    {
        $lid = preg_match('/\A[0-9]{6,24}@lid\z/D', $telefone) === 1;
        $numero = $lid ? $telefone : (preg_replace('/\D+/', '', $telefone) ?? '');
        $r = $this->chamar('POST', '/message/sendText/' . WhatsAppProvedores::sessao($sessao), $token, ['number' => $numero, 'text' => $mensagem]);
        if (!$r->ok() || !is_array($r->json()['key'] ?? null)) {
            throw new IntegracaoErro('O WhatsApp não conseguiu enviar a mensagem. Confira se o número tem WhatsApp e se o celular está conectado.');
        }
    }

    /** Logout com o token da loja e depois apaga a instancia (chave global). Erros sao ignorados. */
    public function sair(string $sessao, string $token): bool
    {
        $nome = WhatsAppProvedores::sessao($sessao);
        $confirmou = false;
        try {
            $confirmou = $this->chamar('DELETE', '/instance/logout/' . $nome, $token)->ok();
        } catch (IntegracaoErro) {
            // segue para apagar
        }
        return $this->apagar($nome) || $confirmou;
    }

    public function sessoesNoServidor(): array
    {
        $r = $this->chamar('GET', '/instance/fetchInstances', $this->chaveGlobal());
        $lista = $r->json();
        if (!$r->ok() || !array_is_list($lista)) {
            throw new IntegracaoErro('O servidor do WhatsApp não listou as sessões (código ' . $r->status . ').');
        }
        $nomes = [];
        foreach ($lista as $item) {
            $nome = is_array($item) ? ($item['name'] ?? $item['instanceName'] ?? $item['instance']['instanceName'] ?? null) : null;
            if (is_string($nome) && preg_match('/\A[a-zA-Z0-9_-]{3,60}\z/', $nome) === 1) {
                $nomes[] = $nome;
            }
        }
        return $nomes;
    }

    public function encerrar(string $sessao): bool
    {
        $nome = WhatsAppProvedores::sessao($sessao);
        try {
            $this->chamar('DELETE', '/instance/logout/' . $nome, $this->chaveGlobal());
        } catch (IntegracaoErro) {
            // instancia sem aparelho conectado: segue para apagar
        }
        return $this->apagar($nome);
    }

    private function apagar(string $nome): bool
    {
        try {
            $r = $this->chamar('DELETE', '/instance/delete/' . $nome, $this->chaveGlobal());
        } catch (IntegracaoErro) {
            return false;
        }
        return $r->ok() || $r->status === 404;
    }

    private function chaveGlobal(): string
    {
        return Config::get('EVOLUTION_API_KEY');
    }

    /** @param array<mixed>|null $corpo */
    private function chamar(string $metodo, string $caminho, string $chave, ?array $corpo = null): RespostaHttp
    {
        if (!$this->configurado()) {
            throw new IntegracaoErro('O WhatsApp ainda não está disponível neste servidor. Avise o suporte do ' . marca() . '.');
        }
        try {
            return $this->http->enviar($metodo, self::urlBase() . $caminho, ['apikey' => $chave], $corpo);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('O servidor do WhatsApp não respondeu: ' . $erro->getMessage());
        }
    }
}
