<?php

declare(strict_types=1);

namespace Cardapim\Services\Cliente;

use Cardapim\Core\Config;
use Cardapim\Modules\Integracoes\SmsGateApi;
use Cardapim\Repositories\Plataforma\SmsGatewayRepository;
use Cardapim\Shared\Http\ClienteHttp;
use PDO;

/**
 * Codigo de login por SMS (SMSGate). Usado automaticamente quando o WhatsApp da plataforma
 * esta indisponivel ou nao consegue entregar o codigo.
 */
final class SmsPlataforma implements CanalCodigo
{
    public function __construct(private readonly SmsGateApi $api = new SmsGateApi())
    {
    }

    /** Usa o que foi cadastrado no painel da plataforma; sem cadastro, o shared/.env. */
    public static function daPlataforma(PDO $db): self
    {
        return new self(self::api($db));
    }

    public static function api(PDO $db, ?ClienteHttp $http = null): SmsGateApi
    {
        $salvo = (new SmsGatewayRepository($db))->buscar();
        return new SmsGateApi($http ?? new ClienteHttp(15), $salvo !== null ? ['url' => $salvo['url'], 'usuario' => $salvo['usuario'], 'senha' => $salvo['senha']] : null);
    }

    public function canal(): string
    {
        return 'sms';
    }

    public function disponivel(): bool
    {
        return $this->api->configurado();
    }

    public function enviarCodigo(string $telefone, string $codigo): void
    {
        $this->api->enviar('55' . $telefone, self::mensagem($codigo), CodigoVerificacao::VALIDADE);
    }

    /**
     * Texto curto (1 SMS, sem acentos que dobram o tamanho e sem formatacao do WhatsApp).
     * A ultima linha no formato "@dominio #codigo" deixa o Chrome no Android preencher o codigo sozinho (WebOTP).
     */
    public static function mensagem(string $codigo): string
    {
        $texto = $codigo . ' e o seu codigo para entrar no ' . self::semAcento(marca()) . '. Vale por 10 minutos. Nao passe para ninguem.';
        $host = (string) parse_url(Config::get('APP_URL'), PHP_URL_HOST);
        return preg_match('/\A[a-z0-9.-]+\z/i', $host) === 1 ? $texto . "\n\n@" . strtolower($host) . ' #' . $codigo : $texto;
    }

    private static function semAcento(string $texto): string
    {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        return is_string($t) && $t !== '' ? preg_replace('/[^A-Za-z0-9 .,-]/', '', $t) ?? $texto : $texto;
    }
}
