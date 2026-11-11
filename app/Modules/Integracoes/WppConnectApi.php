<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * WPPConnect Server proprio do Cardapim (container Docker isolado na VPS, acessivel so por 127.0.0.1).
 * Uma sessao por restaurante. O token de cada sessao e gerado com a SECRET_KEY do servidor
 * (WPPCONNECT_SECRET_KEY no shared/.env) e guardado cifrado no banco.
 */
class WppConnectApi
{
    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(20))
    {
    }

    public static function disponivel(): bool
    {
        return self::urlBase() !== '' && Config::get('WPPCONNECT_SECRET_KEY') !== '';
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('WPPCONNECT_BASE_URL'), '/');
    }

    public function gerarToken(string $sessao): string
    {
        $r = $this->chamar('POST', '/api/' . self::sessao($sessao) . '/' . rawurlencode(Config::get('WPPCONNECT_SECRET_KEY')) . '/generate-token');
        $token = $r->json()['token'] ?? null;
        if (!$r->ok() || !is_string($token) || $token === '') {
            throw new IntegracaoErro('O servidor do WhatsApp recusou a conexão. Avise o suporte do ' . marca() . ' (chave do servidor incorreta).');
        }
        return $token;
    }

    public function iniciar(string $sessao, string $token): void
    {
        $r = $this->chamar('POST', '/api/' . self::sessao($sessao) . '/start-session', $token, ['waitQrCode' => false]);
        if (!$r->ok()) {
            throw new IntegracaoErro('Não foi possível iniciar o WhatsApp agora (código ' . $r->status . '). Tente de novo em instantes.');
        }
    }

    /** @return array{status:string, qrcode:?string} status em maiusculas: CONNECTED, QRCODE, INITIALIZING, CLOSED... */
    public function estado(string $sessao, string $token): array
    {
        $r = $this->chamar('GET', '/api/' . self::sessao($sessao) . '/status-session', $token);
        $d = $r->json();
        $status = strtoupper((string) ($d['status'] ?? ($r->ok() ? 'CLOSED' : 'ERRO')));
        $qr = $d['qrcode'] ?? null;
        return [
            'status' => preg_replace('/[^A-Z_]/', '', $status) ?: 'CLOSED',
            'qrcode' => is_string($qr) && str_starts_with($qr, 'data:image/') ? $qr : null,
        ];
    }

    /** Numero do aparelho conectado (se o servidor informar). */
    public function numero(string $sessao, string $token): ?string
    {
        try {
            $d = $this->chamar('GET', '/api/' . self::sessao($sessao) . '/host-device', $token)->json();
        } catch (IntegracaoErro) {
            return null;
        }
        $r = $d['response'] ?? $d;
        $candidatos = [
            $r['phoneNumber'] ?? null, $r['wid']['user'] ?? null, $r['id']['user'] ?? null,
            is_string($r['wid'] ?? null) ? $r['wid'] : null, is_string($r['id'] ?? null) ? $r['id'] : null,
        ];
        foreach ($candidatos as $c) {
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
        $r = $this->chamar('POST', '/api/' . self::sessao($sessao) . '/send-message', $token, [
            'phone' => $telefone,
            'message' => $mensagem,
            'isGroup' => false,
        ]);
        $status = strtolower((string) ($r->json()['status'] ?? ''));
        if (!$r->ok() || ($status !== '' && $status !== 'success')) {
            throw new IntegracaoErro('O WhatsApp não conseguiu enviar a mensagem. Confira se o número tem WhatsApp e se o celular está conectado.');
        }
    }

    /** Desconecta o aparelho (sai do WhatsApp Web) e fecha a sessao. Erros sao ignorados. */
    public function sair(string $sessao, string $token): void
    {
        foreach (['/logout-session', '/close-session'] as $acao) {
            try {
                $this->chamar('POST', '/api/' . self::sessao($sessao) . $acao, $token);
            } catch (IntegracaoErro) {
                // segue: o objetivo e limpar o que der
            }
        }
    }

    public static function sessao(string $sessao): string
    {
        if (preg_match('/\A[a-zA-Z0-9_-]{3,60}\z/', $sessao) !== 1) {
            throw new IntegracaoErro('Sessão do WhatsApp inválida.');
        }
        return $sessao;
    }

    /** @param array<mixed>|null $corpo */
    private function chamar(string $metodo, string $caminho, ?string $token = null, ?array $corpo = null): RespostaHttp
    {
        if (!self::disponivel()) {
            throw new IntegracaoErro('O WhatsApp ainda não está disponível neste servidor. Avise o suporte do ' . marca() . '.');
        }
        try {
            return $this->http->enviar($metodo, self::urlBase() . $caminho, $token !== null ? ['Authorization' => 'Bearer ' . $token] : [], $corpo ?? ($metodo === 'POST' ? [] : null));
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('O servidor do WhatsApp não respondeu: ' . $erro->getMessage());
        }
    }
}
