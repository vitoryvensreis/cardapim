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

    /** Plataforma (assinaturas dos restaurantes): pagamentos + ciclo de vida das assinaturas. */
    public const EVENTOS_WEBHOOK_PLATAFORMA = [
        ...self::EVENTOS_WEBHOOK,
        'SUBSCRIPTION_CREATED', 'SUBSCRIPTION_UPDATED', 'SUBSCRIPTION_INACTIVATED', 'SUBSCRIPTION_DELETED',
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

    /** Cria o webhook que avisa o Cardapim sobre pagamentos. @param list<string>|null $eventos @return string id do webhook no Asaas */
    public function criarWebhook(string $chave, string $ambiente, string $url, string $tokenAuth, string $email, ?array $eventos = null): string
    {
        $dados = $this->exigirOk($this->chamar('POST', '/webhooks', $chave, $ambiente, [
            'name' => marca(),
            'url' => $url,
            'email' => $email,
            'enabled' => true,
            'interrupted' => false,
            'apiVersion' => 3,
            'authToken' => $tokenAuth,
            'sendType' => 'SEQUENTIALLY',
            'events' => $eventos ?? self::EVENTOS_WEBHOOK,
        ]));
        return (string) ($dados['id'] ?? '');
    }

    /**
     * Situacao cadastral da conta (aprovacao do Asaas): APPROVED, PENDING, AWAITING_APPROVAL ou REJECTED por parte.
     * @return array{geral:string, dados_comerciais:string, conta_bancaria:string, documentos:string}
     */
    public function statusConta(string $chave, string $ambiente): array
    {
        $d = $this->exigirOk($this->chamar('GET', '/myAccount/status', $chave, $ambiente));
        $v = static fn (string $k): string => preg_match('/\A[A-Z_]{3,30}\z/', (string) ($d[$k] ?? '')) === 1 ? (string) $d[$k] : 'DESCONHECIDO';
        return ['geral' => $v('general'), 'dados_comerciais' => $v('commercialInfo'), 'conta_bancaria' => $v('bankAccountInfo'), 'documentos' => $v('documentation')];
    }

    /** Quantas chaves Pix ativas a conta tem (sem chave, o Pix ainda funciona pelo QR do Asaas). */
    public function chavesPixAtivas(string $chave, string $ambiente): int
    {
        $d = $this->exigirOk($this->chamar('GET', '/pix/addressKeys?status=ACTIVE&limit=10', $chave, $ambiente));
        return max(0, (int) ($d['totalCount'] ?? count(is_array($d['data'] ?? null) ? $d['data'] : [])));
    }

    /** Situacao do webhook no Asaas. @return array{ativo:bool, interrompido:bool, eventos:list<string>}|null null = nao existe mais */
    public function webhook(string $chave, string $ambiente, string $idWebhook): ?array
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $idWebhook) !== 1) {
            return null;
        }
        $r = $this->chamar('GET', '/webhooks/' . $idWebhook, $chave, $ambiente);
        if ($r->status === 404) {
            return null;
        }
        $d = $this->exigirOk($r);
        return [
            'ativo' => (bool) ($d['enabled'] ?? false),
            'interrompido' => (bool) ($d['interrupted'] ?? false),
            'eventos' => array_values(array_filter(is_array($d['events'] ?? null) ? $d['events'] : [], 'is_string')),
        ];
    }

    /** Reativa a fila de avisos pausada pelo Asaas (depois de falhas seguidas). */
    public function reativarWebhook(string $chave, string $ambiente, string $idWebhook): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $idWebhook) !== 1) {
            throw new IntegracaoErro('Avisos não encontrados no Asaas.');
        }
        $this->exigirOk($this->chamar('PUT', '/webhooks/' . $idWebhook, $chave, $ambiente, ['enabled' => true, 'interrupted' => false]));
    }

    public function removerWebhook(string $chave, string $ambiente, string $idWebhook): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $idWebhook) !== 1) {
            return;
        }
        $this->chamar('DELETE', '/webhooks/' . $idWebhook, $chave, $ambiente);
    }

    /** Cadastra o pagador na conta Asaas do restaurante (exigido para cobrar). CPF ou CNPJ; celular opcional. @return string id (cus_...) */
    public function criarCliente(string $chave, string $ambiente, string $nome, string $cpf, string $celular, string $referencia): string
    {
        $corpo = ['name' => $nome, 'cpfCnpj' => $cpf, 'externalReference' => $referencia, 'notificationDisabled' => true];
        if ($celular !== '') {
            $corpo['mobilePhone'] = $celular;
        }
        $dados = $this->exigirOk($this->chamar('POST', '/customers', $chave, $ambiente, $corpo));
        $id = (string) ($dados['id'] ?? '');
        if (preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $id) !== 1) {
            throw new IntegracaoErro('O Asaas não devolveu o cadastro do cliente.');
        }
        return $id;
    }

    /**
     * Cobranca Pix de um pedido. @return string id da cobranca (pay_...)
     * externalReference = "pedido:{id}" liga o aviso (webhook) ao pedido.
     */
    public function criarCobrancaPix(string $chave, string $ambiente, string $cliente, string $valor, string $vencimento, string $descricao, string $referencia): string
    {
        $dados = $this->exigirOk($this->chamar('POST', '/payments', $chave, $ambiente, [
            'customer' => $cliente,
            'billingType' => 'PIX',
            'value' => (float) $valor,
            'dueDate' => $vencimento,
            'description' => mb_substr($descricao, 0, 500),
            'externalReference' => $referencia,
        ]));
        $id = (string) ($dados['id'] ?? '');
        if (preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $id) !== 1) {
            throw new IntegracaoErro('O Asaas não devolveu a cobrança Pix.');
        }
        return $id;
    }

    /** Codigo Pix copia e cola da cobranca. @return array{payload:string, expira_em:?string} */
    public function qrCodePix(string $chave, string $ambiente, string $idCobranca): array
    {
        $dados = $this->exigirOk($this->chamar('GET', '/payments/' . self::idSeguro($idCobranca) . '/pixQrCode', $chave, $ambiente));
        $payload = (string) ($dados['payload'] ?? '');
        if ($payload === '' || strlen($payload) > 1000) {
            throw new IntegracaoErro('O Asaas não devolveu o código Pix.');
        }
        $expira = is_string($dados['expirationDate'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?\z/', $dados['expirationDate']) === 1
            ? $dados['expirationDate'] : null;
        return ['payload' => $payload, 'expira_em' => $expira];
    }

    /** Situacao da cobranca (PENDING, RECEIVED, CONFIRMED, OVERDUE...). @return array{status:string, valor:string} */
    public function cobranca(string $chave, string $ambiente, string $idCobranca): array
    {
        $dados = $this->exigirOk($this->chamar('GET', '/payments/' . self::idSeguro($idCobranca), $chave, $ambiente));
        return [
            'status' => strtoupper((string) ($dados['status'] ?? '')),
            'valor' => number_format((float) ($dados['value'] ?? 0), 2, '.', ''),
        ];
    }

    /** Cancela (remove) a cobranca: usado quando o pedido expira ou e cancelado antes do pagamento. */
    public function cancelarCobranca(string $chave, string $ambiente, string $idCobranca): void
    {
        $this->chamar('DELETE', '/payments/' . self::idSeguro($idCobranca), $chave, $ambiente);
    }

    /**
     * Cliente da PLATAFORMA (restaurante que assina): nome, CNPJ, e-mail e celular.
     * $avisos = o Asaas envia fatura e lembretes por e-mail/SMS (regra "avisos_asaas"). @return string id (cus_...)
     */
    public function criarClienteAssinante(string $chave, string $ambiente, string $nome, string $cnpj, string $email, string $celular, string $referencia, bool $avisos): string
    {
        $corpo = ['name' => mb_substr($nome, 0, 100), 'cpfCnpj' => $cnpj, 'externalReference' => $referencia, 'notificationDisabled' => !$avisos];
        if ($email !== '') {
            $corpo['email'] = $email;
        }
        if ($celular !== '') {
            $corpo['mobilePhone'] = $celular;
        }
        $dados = $this->exigirOk($this->chamar('POST', '/customers', $chave, $ambiente, $corpo));
        return self::idDevolvido($dados, 'O Asaas não devolveu o cadastro do restaurante.');
    }

    /**
     * Assinatura recorrente (POST /v3/subscriptions). $corpo ja no formato do Asaas: customer, billingType, value,
     * nextDueDate, cycle, description, externalReference, fine/interest/discount. @return string id (sub_...)
     */
    public function criarAssinatura(string $chave, string $ambiente, array $corpo): string
    {
        $dados = $this->exigirOk($this->chamar('POST', '/subscriptions', $chave, $ambiente, $corpo));
        return self::idDevolvido($dados, 'O Asaas não devolveu a assinatura.');
    }

    /** Muda valor/ciclo (vale para as proximas faturas e para as pendentes). */
    public function atualizarAssinatura(string $chave, string $ambiente, string $id, array $corpo): void
    {
        $this->exigirOk($this->chamar('PUT', '/subscriptions/' . self::idSeguro($id), $chave, $ambiente, $corpo));
    }

    /** Remove a assinatura (o Asaas apaga as faturas ainda nao pagas). */
    public function removerAssinatura(string $chave, string $ambiente, string $id): void
    {
        $r = $this->chamar('DELETE', '/subscriptions/' . self::idSeguro($id), $chave, $ambiente);
        if ($r->status !== 404) {
            $this->exigirOk($r);
        }
    }

    /**
     * Faturas geradas pela assinatura, ja no formato do Cardapim.
     * @return list<array{id:string,status:string,valor:string,vencimento:string,forma:string,link_fatura:?string,link_boleto:?string,pago_em:?string,assinatura:?string}>
     */
    public function faturasDaAssinatura(string $chave, string $ambiente, string $id): array
    {
        $dados = $this->exigirOk($this->chamar('GET', '/subscriptions/' . self::idSeguro($id) . '/payments?limit=50', $chave, $ambiente));
        $lista = [];
        foreach (is_array($dados['data'] ?? null) ? $dados['data'] : [] as $p) {
            if (is_array($p) && ($f = self::fatura($p)) !== null) {
                $lista[] = $f;
            }
        }
        return $lista;
    }

    /**
     * Normaliza uma cobranca do Asaas (lista ou "payment" do webhook). null se faltar o essencial.
     * @param array<string,mixed> $p
     * @return array{id:string,status:string,valor:string,vencimento:string,forma:string,link_fatura:?string,link_boleto:?string,pago_em:?string,assinatura:?string}|null
     */
    public static function fatura(array $p): ?array
    {
        $id = (string) ($p['id'] ?? '');
        $venc = (string) ($p['dueDate'] ?? '');
        if (preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $id) !== 1 || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $venc) !== 1) {
            return null;
        }
        $url = static function (mixed $v): ?string {
            return is_string($v) && str_starts_with($v, 'https://') && strlen($v) <= 500 && filter_var($v, FILTER_VALIDATE_URL) !== false ? $v : null;
        };
        $pago = null;
        foreach (['clientPaymentDate', 'paymentDate', 'confirmedDate'] as $campo) {
            if (is_string($p[$campo] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}/', $p[$campo]) === 1) {
                $pago = substr($p[$campo], 0, 10);
                break;
            }
        }
        $assinatura = is_string($p['subscription'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $p['subscription']) === 1 ? $p['subscription'] : null;
        return [
            'id' => $id,
            'status' => strtoupper((string) ($p['status'] ?? '')),
            'valor' => number_format(max(0, (float) ($p['value'] ?? 0)), 2, '.', ''),
            'vencimento' => $venc,
            'forma' => strtoupper(substr((string) ($p['billingType'] ?? ''), 0, 20)),
            'link_fatura' => $url($p['invoiceUrl'] ?? null),
            'link_boleto' => $url($p['bankSlipUrl'] ?? null),
            'pago_em' => $pago,
            'assinatura' => $assinatura,
        ];
    }

    /** @param array<string,mixed> $dados */
    private static function idDevolvido(array $dados, string $erro): string
    {
        $id = (string) ($dados['id'] ?? '');
        if (preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $id) !== 1) {
            throw new IntegracaoErro($erro);
        }
        return $id;
    }

    private static function idSeguro(string $id): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{3,60}\z/', $id) !== 1) {
            throw new IntegracaoErro('Identificador de cobrança inválido.');
        }
        return $id;
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
