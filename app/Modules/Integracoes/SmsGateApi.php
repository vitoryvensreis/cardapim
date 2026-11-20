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
 * Configuracao: cadastrada no painel da plataforma (/painel/integracoes/sms, cifrada no banco) e passada
 * no construtor. Sem ela, vale o shared/.env da VPS:
 * - SMSGATE_URL: base da API de terceiros, ex.: https://sms.escotilhaprodutora.com/api/3rdparty/v1
 * - SMSGATE_USUARIO e SMSGATE_SENHA: credenciais geradas pelo app no celular (Settings > Cloud Server).
 */
class SmsGateApi
{
    /** Prioridade >= 100: o app ignora limites, atrasos e horario de envio (codigo nao pode esperar). */
    public const PRIORIDADE_URGENTE = 100;

    /** @var array{url:string, usuario:string, senha:string} */
    private readonly array $acesso;

    /** @param array{url:string, usuario:string, senha:string}|null $credenciais null = usa o shared/.env */
    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(15), ?array $credenciais = null)
    {
        $this->acesso = $credenciais !== null
            ? ['url' => rtrim($credenciais['url'], '/'), 'usuario' => $credenciais['usuario'], 'senha' => $credenciais['senha']]
            : ['url' => rtrim(Config::get('SMSGATE_URL'), '/'), 'usuario' => Config::get('SMSGATE_USUARIO'), 'senha' => Config::get('SMSGATE_SENHA')];
    }

    public function configurado(): bool
    {
        return $this->acesso['url'] !== '' && $this->acesso['usuario'] !== '' && $this->acesso['senha'] !== '';
    }

    public function urlBase(): string
    {
        return $this->acesso['url'];
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
        $autorizacao = 'Basic ' . base64_encode($this->acesso['usuario'] . ':' . $this->acesso['senha']);
        try {
            $r = $this->http->enviar('POST', $this->acesso['url'] . '/messages', ['Authorization' => $autorizacao], [
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
