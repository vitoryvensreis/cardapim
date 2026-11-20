<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;

/**
 * SMSGate (SMS Gateway for Android, servidor privado na VPS de mensageria): o SMS sai pelo chip de um
 * celular Android conectado ao servidor. Ver docs/deploy/sms.md.
 *
 * Configuracao (so no shared/.env da VPS, nunca no Git):
 * - SMSGATE_URL: base da API de terceiros, ex.: https://sms.escotilhaprodutora.com/api/3rdparty/v1
 * - SMSGATE_USUARIO e SMSGATE_SENHA: credenciais geradas pelo app no celular (Settings > Cloud Server).
 */
class SmsGateApi
{
    /** Prioridade >= 100: o app ignora limites, atrasos e horario de envio (codigo nao pode esperar). */
    public const PRIORIDADE_URGENTE = 100;

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(15))
    {
    }

    public function configurado(): bool
    {
        return self::urlBase() !== '' && Config::get('SMSGATE_USUARIO') !== '' && Config::get('SMSGATE_SENHA') !== '';
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('SMSGATE_URL'), '/');
    }

    /**
     * Coloca o SMS na fila do celular. O envio pelo chip acontece logo depois (assincrono).
     * @param string $telefone digitos com DDI, ex.: 5585999990000
     * @param int $validadeSegundos depois disso o celular descarta o SMS que ainda nao saiu
     * @return string id da mensagem no SMSGate
     */
    public function enviar(string $telefone, string $texto, int $validadeSegundos = 600): string
    {
        $digitos = preg_replace('/\D+/', '', $telefone) ?? '';
        if (preg_match('/\A[1-9][0-9]{9,14}\z/', $digitos) !== 1) {
            throw new IntegracaoErro('Número de celular inválido para SMS.');
        }
        if (!$this->configurado()) {
            throw new IntegracaoErro('O envio por SMS não está configurado neste servidor.');
        }
        $autorizacao = 'Basic ' . base64_encode(Config::get('SMSGATE_USUARIO') . ':' . Config::get('SMSGATE_SENHA'));
        try {
            $r = $this->http->enviar('POST', self::urlBase() . '/messages', ['Authorization' => $autorizacao], [
                'textMessage' => ['text' => $texto],
                'phoneNumbers' => ['+' . $digitos],
                'priority' => self::PRIORIDADE_URGENTE,
                'ttl' => max(5, $validadeSegundos),
            ]);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('O servidor de SMS não respondeu: ' . $erro->getMessage());
        }
        $id = $r->json()['id'] ?? null;
        if (!$r->ok() || !is_string($id) || $id === '') {
            throw new IntegracaoErro($r->status === 401 || $r->status === 403
                ? 'O servidor de SMS recusou o acesso (usuário ou senha do SMSGate incorretos).'
                : 'Não foi possível enviar o SMS agora (código ' . $r->status . ').');
        }
        return $id;
    }
}
