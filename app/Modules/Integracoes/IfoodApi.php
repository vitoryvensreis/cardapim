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
        if (in_array($r->status, [400, 401, 403], true)) {
            throw new IntegracaoErro('O iFood recusou ao ' . $acao . '. Se você acabou de colar o código, confira se copiou certo e se ele não venceu (vale 5 minutos).');
        }
        throw new IntegracaoErro('O iFood não respondeu como esperado ao ' . $acao . ' (código ' . $r->status . '). Tente de novo em instantes.');
    }
}
