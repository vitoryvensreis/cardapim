<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * API do iFood (Merchant API) no fluxo de APLICATIVO DISTRIBUIDO:
 * o Cardapim tem um app no Portal do Desenvolvedor (IFOOD_CLIENT_ID/SECRET no shared/.env) e cada
 * restaurante autoriza esse app no Portal do Parceiro com um codigo de vinculo.
 */
class IfoodApi
{
    public function __construct(private readonly ClienteHttp $http = new ClienteHttp())
    {
    }

    public static function disponivel(): bool
    {
        return Config::get('IFOOD_CLIENT_ID') !== '' && Config::get('IFOOD_CLIENT_SECRET') !== '';
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'), '/');
    }

    /** @return array{codigo:string, verificador:string, url:string, expira:int} */
    public function codigoVinculo(): array
    {
        $r = $this->post('/authentication/v1.0/oauth/userCode', ['clientId' => Config::get('IFOOD_CLIENT_ID')]);
        $d = $this->exigirOk($r, 'gerar o código de vínculo');
        $codigo = (string) ($d['userCode'] ?? '');
        $verificador = (string) ($d['authorizationCodeVerifier'] ?? '');
        if ($codigo === '' || $verificador === '') {
            throw new IntegracaoErro('O iFood não devolveu o código de vínculo. Tente de novo em instantes.');
        }
        $url = (string) ($d['verificationUrlComplete'] ?? $d['verificationUrl'] ?? 'https://portal.ifood.com.br/apps/code');
        return [
            'codigo' => mb_substr($codigo, 0, 20),
            'verificador' => $verificador,
            'url' => str_starts_with($url, 'https://') ? $url : 'https://portal.ifood.com.br/apps/code',
            'expira' => time() + max(60, min(3600, (int) ($d['expiresIn'] ?? 600))),
        ];
    }

    /** @return array{acesso:string, renovacao:string, expira:int} */
    public function trocarCodigo(string $codigoAutorizacao, string $verificador): array
    {
        return $this->tokens($this->post('/authentication/v1.0/oauth/token', [
            'grantType' => 'authorization_code',
            'clientId' => Config::get('IFOOD_CLIENT_ID'),
            'clientSecret' => Config::get('IFOOD_CLIENT_SECRET'),
            'authorizationCode' => $codigoAutorizacao,
            'authorizationCodeVerifier' => $verificador,
        ]), 'confirmar o código de autorização');
    }

    /** @return array{acesso:string, renovacao:string, expira:int} */
    public function renovar(string $tokenRenovacao): array
    {
        return $this->tokens($this->post('/authentication/v1.0/oauth/token', [
            'grantType' => 'refresh_token',
            'clientId' => Config::get('IFOOD_CLIENT_ID'),
            'clientSecret' => Config::get('IFOOD_CLIENT_SECRET'),
            'refreshToken' => $tokenRenovacao,
        ]), 'renovar o acesso');
    }

    /** @return list<array{id:string,nome:string}> lojas que autorizaram o app */
    public function lojas(string $tokenAcesso): array
    {
        $d = $this->exigirOk($this->get('/merchant/v1.0/merchants', $tokenAcesso), 'listar as lojas');
        $lojas = [];
        foreach ($d as $loja) {
            if (is_array($loja) && is_string($loja['id'] ?? null) && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $loja['id']) === 1) {
                $lojas[] = ['id' => $loja['id'], 'nome' => mb_substr((string) ($loja['name'] ?? $loja['corporateName'] ?? $loja['id']), 0, 120)];
            }
        }
        return $lojas;
    }

    /** Situacao da loja no iFood. @return array{aberta:?bool, mensagem:string} */
    public function statusLoja(string $tokenAcesso, string $idLoja): array
    {
        $d = $this->exigirOk($this->get('/merchant/v1.0/merchants/' . rawurlencode($idLoja) . '/status', $tokenAcesso), 'consultar a loja');
        $item = isset($d[0]) && is_array($d[0]) ? $d[0] : $d;
        $estado = strtoupper((string) ($item['state'] ?? ''));
        $titulo = (string) ($item['message']['title'] ?? '');
        return [
            'aberta' => $estado === '' ? null : in_array($estado, ['OK', 'WARNING'], true) && ($item['available'] ?? true) !== false,
            'mensagem' => mb_substr($titulo !== '' ? $titulo : ($estado !== '' ? 'Situação: ' . $estado : 'Loja encontrada no iFood.'), 0, 160),
        ];
    }

    /* ---------- Pedidos (polling, confirmacao de leitura, detalhes e acoes) ---------- */

    /**
     * Avisos novos da loja. O iFood exige consulta a cada 30 s (mantem a loja aberta) e nunca mais rapido.
     * @return list<array<string,mixed>>
     */
    public function eventos(string $token, string $idLoja): array
    {
        $r = $this->requisicao('GET', '/events/v1.0/events:polling', $token, null, ['x-polling-merchants' => $idLoja]);
        if ($r->status === 204) {
            return [];
        }
        $d = $this->exigirOk($r, 'buscar os pedidos novos');
        return array_values(array_filter($d, static fn (mixed $ev): bool => is_array($ev) && is_string($ev['id'] ?? null)));
    }

    /** Confirma ao iFood que os avisos foram recebidos (obrigatorio; ate 2000 por vez). @param list<string> $ids */
    public function confirmarEventos(string $token, array $ids): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 2000) as $lote) {
            $r = $this->requisicao('POST', '/events/v1.0/events/acknowledgment', $token, array_map(static fn (string $id): array => ['id' => $id], $lote));
            $this->exigirOk($r, 'confirmar o recebimento dos avisos');
        }
    }

    /** Detalhes completos do pedido. @return array<string,mixed> */
    public function pedido(string $token, string $idPedido): array
    {
        return $this->exigirOk($this->requisicao('GET', '/order/v1.0/orders/' . self::id($idPedido), $token), 'buscar o pedido');
    }

    /**
     * Acao no pedido: confirm, startPreparation, readyToPickup, dispatch ou requestCancellation.
     * @param array<string,mixed>|null $corpo
     */
    public function acaoPedido(string $token, string $idPedido, string $acao, ?array $corpo = null): void
    {
        if (!in_array($acao, ['confirm', 'startPreparation', 'readyToPickup', 'dispatch', 'requestCancellation'], true)) {
            throw new IntegracaoErro('Ação do iFood inválida.');
        }
        $r = $this->requisicao('POST', '/order/v1.0/orders/' . self::id($idPedido) . '/' . $acao, $token, $corpo);
        $this->exigirOk($r, 'atualizar o pedido');
    }

    /** Posicao e previsao do entregador do iFood (null enquanto o iFood nao tem). @return array<string,mixed>|null */
    public function rastreio(string $token, string $idPedido): ?array
    {
        $r = $this->requisicao('GET', '/order/v1.0/orders/' . self::id($idPedido) . '/tracking', $token);
        if ($r->status === 404 || $r->status === 204) {
            return null;
        }
        return $this->exigirOk($r, 'buscar o entregador');
    }

    /* ---------- Cardapio (itens e disponibilidade) ---------- */

    /** @return list<string> ids dos cardapios da loja */
    public function catalogos(string $token, string $idLoja): array
    {
        $d = $this->exigirOk($this->requisicao('GET', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/catalogs', $token), 'buscar o cardápio');
        $ids = [];
        foreach ($d as $c) {
            if (is_array($c) && is_string($c['catalogId'] ?? null) && strtoupper((string) ($c['status'] ?? 'AVAILABLE')) !== 'DELETED') {
                $ids[] = $c['catalogId'];
            }
        }
        return $ids;
    }

    /**
     * Itens de um cardapio da loja, ja simplificados.
     * @return list<array{id:string, produto:?string, nome:string, codigo:?string, categoria:?string, preco:?float, status:?string}>
     */
    public function itensCatalogo(string $token, string $idLoja, string $idCatalogo): array
    {
        $r = $this->requisicao('GET', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/catalogs/' . self::id($idCatalogo) . '/categories?includeItems=true', $token);
        $d = $this->exigirOk($r, 'buscar os itens do cardápio');
        $itens = [];
        foreach ($d as $categoria) {
            if (!is_array($categoria)) {
                continue;
            }
            foreach (is_array($categoria['items'] ?? null) ? $categoria['items'] : [] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '') {
                    continue;
                }
                $preco = $item['price']['value'] ?? $item['price'] ?? null;
                $itens[] = [
                    'id' => mb_substr($item['id'], 0, 64),
                    'produto' => is_string($item['productId'] ?? null) ? mb_substr($item['productId'], 0, 64) : null,
                    'nome' => mb_substr(trim((string) ($item['name'] ?? $item['description'] ?? 'Item sem nome')), 0, 180),
                    'codigo' => is_scalar($item['externalCode'] ?? null) && trim((string) $item['externalCode']) !== '' ? mb_substr(trim((string) $item['externalCode']), 0, 80) : null,
                    'categoria' => is_string($categoria['name'] ?? null) ? mb_substr($categoria['name'], 0, 120) : null,
                    'preco' => is_numeric($preco) ? (float) $preco : null,
                    'status' => is_string($item['status'] ?? null) ? mb_substr(strtoupper($item['status']), 0, 20) : null,
                ];
            }
        }
        return $itens;
    }

    /** Liga ou desliga a venda do item no iFood. */
    public function disponibilidadeItem(string $token, string $idLoja, string $idItem, bool $disponivel): void
    {
        $r = $this->requisicao('PATCH', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/items/status', $token,
            ['itemId' => $idItem, 'status' => $disponivel ? 'AVAILABLE' : 'UNAVAILABLE']);
        $this->exigirOk($r, 'mudar a disponibilidade do item');
    }

    /** Quantidade em estoque do produto no iFood (no zero o iFood para de vender sozinho). */
    public function estoqueProduto(string $token, string $idLoja, string $idProduto, int $quantidade): void
    {
        $r = $this->requisicao('POST', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/inventory', $token,
            ['productId' => $idProduto, 'amount' => max(0, $quantidade)]);
        $this->exigirOk($r, 'atualizar o estoque do item');
    }

    /**
     * @param array<mixed>|null $corpo JSON
     * @param array<string,string> $extras cabecalhos extras
     */
    private function requisicao(string $metodo, string $caminho, string $token, ?array $corpo = null, array $extras = []): RespostaHttp
    {
        try {
            return $this->http->enviar($metodo, self::urlBase() . $caminho, ['Authorization' => 'Bearer ' . $token] + $extras, $corpo);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('Não foi possível falar com o iFood agora: ' . $erro->getMessage());
        } catch (\JsonException) {
            throw new IntegracaoErro('Dados inválidos para o iFood.');
        }
    }

    /** Id vindo do iFood usado no endereco: so letras, numeros e hifen. */
    private static function id(string $id): string
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $id) !== 1) {
            throw new IntegracaoErro('Código do iFood inválido.');
        }
        return $id;
    }

    /** @return array{acesso:string, renovacao:string, expira:int} */
    private function tokens(RespostaHttp $r, string $acao): array
    {
        $d = $this->exigirOk($r, $acao);
        $acesso = (string) ($d['accessToken'] ?? $d['access_token'] ?? '');
        if ($acesso === '') {
            throw new IntegracaoErro('O iFood não devolveu o acesso. Gere um novo código e tente de novo.');
        }
        return [
            'acesso' => $acesso,
            'renovacao' => (string) ($d['refreshToken'] ?? $d['refresh_token'] ?? ''),
            'expira' => time() + max(300, (int) ($d['expiresIn'] ?? $d['expires_in'] ?? 10800)) - 120,
        ];
    }

    /** @param array<string,string> $campos */
    private function post(string $caminho, array $campos): RespostaHttp
    {
        if (!self::disponivel()) {
            throw new IntegracaoErro('A integração com o iFood ainda não está liberada neste servidor. Avise o suporte do ' . marca() . '.');
        }
        try {
            return $this->http->formulario(self::urlBase() . $caminho, $campos);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('Não foi possível falar com o iFood agora: ' . $erro->getMessage());
        }
    }

    private function get(string $caminho, string $token): RespostaHttp
    {
        try {
            return $this->http->enviar('GET', self::urlBase() . $caminho, ['Authorization' => 'Bearer ' . $token]);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('Não foi possível falar com o iFood agora: ' . $erro->getMessage());
        }
    }

    /** @return array<mixed> */
    private function exigirOk(RespostaHttp $r, string $acao): array
    {
        if ($r->ok()) {
            return $r->json();
        }
        if (in_array($r->status, [400, 401, 403], true) && str_contains($acao, 'código')) {
            throw new IntegracaoErro('O iFood recusou ao ' . $acao . '. Se você acabou de colar o código, confira se copiou certo e se ele não venceu (vale 5 minutos).', $r->status);
        }
        if ($r->status === 401 || $r->status === 403) {
            throw new IntegracaoErro('O iFood recusou o acesso ao ' . $acao . '. Se a autorização foi removida no Portal do Parceiro, conecte de novo.', $r->status);
        }
        throw new IntegracaoErro('O iFood não respondeu como esperado ao ' . $acao . ' (código ' . $r->status . '). Tente de novo em instantes.', $r->status);
    }
}
