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
 * Implementa o contrato comum ProvedorWhatsApp (a Evolution API e o outro provedor).
 */
class WppConnectApi implements ProvedorWhatsApp
{
    /**
     * $http: chamadas normais (20 s). $httpRapido: teste rapido de "a sessao responde?" (8 s), para a
     * tela nao ficar presa quando o navegador da sessao travou no servidor.
     */
    public function __construct(
        private readonly ClienteHttp $http = new ClienteHttp(20),
        private readonly ClienteHttp $httpRapido = new ClienteHttp(8),
    ) {
    }

    public static function disponivel(): bool
    {
        return self::urlBase() !== '' && Config::get('WPPCONNECT_SECRET_KEY') !== '';
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('WPPCONNECT_BASE_URL'), '/');
    }

    public function id(): string
    {
        return WhatsAppProvedores::WPPCONNECT;
    }

    public function configurado(): bool
    {
        return self::disponivel();
    }

    /** No WPPConnect a sessao nasce ao gerar o token (o start-session vem depois, em iniciar()). */
    public function criarSessao(string $sessao): string
    {
        return $this->gerarToken($sessao);
    }

    /** Sessao abandonada: gera um token com a chave do servidor so para fechar. */
    public function encerrar(string $sessao): bool
    {
        try {
            return $this->sair($sessao, $this->gerarToken($sessao));
        } catch (IntegracaoErro) {
            return false;
        }
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

    /**
     * A sessao responde de verdade? O status-session pode dizer CONNECTED mesmo com o navegador da
     * sessao travado (ex.: sem memoria). check-connection-session executa algo dentro da pagina.
     * true = respondeu conectado; false = demorou demais ou respondeu desconectado; null = nao deu para saber
     * (servidor sem essa rota ou outro erro): nesse caso a tela segue o status normal.
     */
    public function respondendo(string $sessao, string $token): ?bool
    {
        if (!self::disponivel()) {
            return null;
        }
        try {
            $r = $this->httpRapido->enviar('GET', self::urlBase() . '/api/' . self::sessao($sessao) . '/check-connection-session', ['Authorization' => 'Bearer ' . $token]);
        } catch (ErroHttp) {
            return false; // nao respondeu a tempo: e o sintoma da sessao travada
        }
        if ($r->status === 404 || $r->status === 401) {
            return null;
        }
        $status = $r->json()['status'] ?? null;
        return is_bool($status) ? $status : null;
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
            // O servidor reconhece o dominio explicito @lid; nao interpreta LID
            // como telefone, evitando criar conversa com destinatario incorreto.
            'isLid' => preg_match('/\A[0-9]{6,24}@lid\z/D', $telefone) === 1,
        ]);
        $status = strtolower((string) ($r->json()['status'] ?? ''));
        if (!$r->ok() || ($status !== '' && $status !== 'success')) {
            throw new IntegracaoErro('O WhatsApp não conseguiu enviar a mensagem. Confira se o número tem WhatsApp e se o celular está conectado.');
        }
    }

    /**
     * Desconecta o aparelho (sai do WhatsApp Web) e fecha a sessao. Erros sao ignorados.
     * Devolve true se o servidor confirmou a saida (logout). false = a sessao pode ter ficado aberta
     * no servidor (ex.: travada); a rotina bin/whatsapp-sessoes.php fecha as que sobrarem.
     */
    public function sair(string $sessao, string $token): bool
    {
        $confirmou = false;
        foreach (['/logout-session', '/close-session'] as $acao) {
            try {
                $r = $this->chamar('POST', '/api/' . self::sessao($sessao) . $acao, $token);
                $confirmou = $confirmou || ($acao === '/logout-session' && $r->ok());
            } catch (IntegracaoErro) {
                // segue: o objetivo e limpar o que der
            }
        }
        return $confirmou;
    }

    /**
     * Nomes de todas as sessoes que o servidor conhece (abertas ou salvas para religar).
     * @return list<string>
     */
    public function sessoesNoServidor(): array
    {
        if (!self::disponivel()) {
            throw new IntegracaoErro('O WhatsApp ainda não está disponível neste servidor.');
        }
        try {
            $r = $this->http->enviar('POST', self::urlBase() . '/api/show-all-sessions', ['x-secret-key' => Config::get('WPPCONNECT_SECRET_KEY')], []);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('O servidor do WhatsApp não respondeu: ' . $erro->getMessage());
        }
        $lista = $r->json()['response'] ?? null;
        if (!$r->ok() || !is_array($lista)) {
            throw new IntegracaoErro('O servidor do WhatsApp não listou as sessões (código ' . $r->status . ').');
        }
        return array_values(array_filter($lista, static fn ($s): bool => is_string($s) && preg_match('/\A[a-zA-Z0-9_-]{3,60}\z/', $s) === 1));
    }

    public static function sessao(string $sessao): string
    {
        return WhatsAppProvedores::sessao($sessao);
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
