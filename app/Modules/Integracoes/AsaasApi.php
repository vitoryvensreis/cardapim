<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * API do Asaas (conta do PROPRIO restaurante). Autenticacao pelo cabecalho access_token
 * e User-Agent obrigatorio. Sandbox e producao tem enderecos e chaves diferentes.
 */
class AsaasApi
{
    public const EVENTOS_WEBHOOK = [
        'PAYMENT_CREATED', 'PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED', 'PAYMENT_OVERDUE',
        'PAYMENT_REFUNDED', 'PAYMENT_DELETED', 'PAYMENT_UPDATED',
    ];

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp())
    {
    }

    public static function urlBase(string $ambiente): string
    {
        return rtrim($ambiente === 'producao'
            ? Config::get('ASAAS_URL_PRODUCAO', 'https://api.asaas.com/v3')
            : Config::get('ASAAS_URL_SANDBOX', 'https://api-sandbox.asaas.com/v3'), '/');
    }

    /** Confere a chave consultando o saldo. @return string saldo decimal ("123.45") */
    public function saldo(string $chave, string $ambiente): string
    {
        $dados = $this->exigirOk($this->chamar('GET', '/finance/balance', $chave, $ambiente));
        return number_format((float) ($dados['balance'] ?? 0), 2, '.', '');
    }

    /** Nome/e-mail da conta (opcional: se falhar, devolve vazio). @return array{nome:string,email:string} */
    public function conta(string $chave, string $ambiente): array
    {
        try {
            $r = $this->chamar('GET', '/myAccount/commercialInfo', $chave, $ambiente);
            $d = $r->ok() ? $r->json() : [];
        } catch (ErroHttp) {
            $d = [];
        }
        return [
            'nome' => mb_substr((string) ($d['companyName'] ?? $d['name'] ?? ''), 0, 120),
            'email' => mb_substr((string) ($d['email'] ?? ''), 0, 190),
        ];
    }

    /** Cria o webhook que avisa o Cardapim sobre pagamentos. @return string id do webhook no Asaas */
    public function criarWebhook(string $chave, string $ambiente, string $url, string $tokenAuth, string $email): string
    {
        $dados = $this->exigirOk($this->chamar('POST', '/webhooks', $chave, $ambiente, [
            'name' => 'Cardapim',
            'url' => $url,
            'email' => $email,
            'enabled' => true,
            'interrupted' => false,
            'apiVersion' => 3,
            'authToken' => $tokenAuth,
            'sendType' => 'SEQUENTIALLY',
            'events' => self::EVENTOS_WEBHOOK,
        ]));
        return (string) ($dados['id'] ?? '');
    }

    public function removerWebhook(string $chave, string $ambiente, string $idWebhook): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $idWebhook) !== 1) {
            return;
        }
        $this->chamar('DELETE', '/webhooks/' . $idWebhook, $chave, $ambiente);
    }

    /** @param array<mixed>|null $corpo */
    private function chamar(string $metodo, string $caminho, string $chave, string $ambiente, ?array $corpo = null): RespostaHttp
    {
        try {
            return $this->http->enviar($metodo, self::urlBase($ambiente) . $caminho, [
                'access_token' => $chave,
                'User-Agent' => 'Cardapim/1.0',
            ], $corpo);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('Não foi possível falar com o Asaas agora: ' . $erro->getMessage() . ' Tente de novo em instantes.');
        }
    }

    /** @return array<mixed> */
    private function exigirOk(RespostaHttp $r): array
    {
        if ($r->ok()) {
            return $r->json();
        }
        if ($r->status === 401) {
            throw new IntegracaoErro('O Asaas recusou a chave de API. Confira se copiou a chave inteira e se ela é do ambiente escolhido (Sandbox ou Produção).');
        }
        $erro = $r->json()['errors'][0]['description'] ?? null;
        throw new IntegracaoErro(is_string($erro) && $erro !== ''
            ? 'O Asaas respondeu: ' . mb_substr($erro, 0, 180)
            : 'O Asaas não aceitou o pedido (código ' . $r->status . '). Tente de novo em instantes.');
    }
}
