<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Repositories\Plataforma\AplicativoParceiroRepository;

/**
 * Onde o sistema busca o Client ID / Client Secret do app da plataforma em cada marketplace.
 * Ordem: 1) cadastrado no painel da plataforma (banco, cifrado); 2) shared/.env (modo antigo, continua valendo);
 * 3) nada = integracao ainda nao liberada.
 * Leitura em cache por 60 s, para as rotinas longas (bin/ifood.php) pegarem uma troca sem reiniciar.
 */
final class AplicativosParceiros
{
    /** provedor => nome na tela, variaveis do .env, portal onde o app e criado, rotulo do Client ID */
    public const PROVEDORES = [
        'ifood' => [
            'nome' => 'iFood',
            'env_id' => 'IFOOD_CLIENT_ID',
            'env_secret' => 'IFOOD_CLIENT_SECRET',
            'portal' => 'Portal do Desenvolvedor iFood (developer.ifood.com.br) > Meus aplicativos',
            'rotulo_id' => 'Client ID',
            'pronta' => true,
        ],
        '99food' => [
            'nome' => '99Food',
            'env_id' => 'NOVENOVEFOOD_APP_ID',
            'env_secret' => 'NOVENOVEFOOD_CLIENT_SECRET',
            'portal' => 'Portal de desenvolvedor da 99Food (developer-food.99app.com) > Gerenciamento de Aplicativo',
            'rotulo_id' => 'App ID (Client ID)',
            'pronta' => false,
        ],
    ];

    private const CACHE_SEGUNDOS = 60;

    /** @var array<string, array{client_id:string, client_secret:string, origem:string}> */
    private static array $cache = [];
    private static int $carregadoEm = 0;

    public static function existe(string $provedor): bool
    {
        return isset(self::PROVEDORES[$provedor]);
    }

    /** @return array{client_id:string, client_secret:string, origem:string} origem: painel | env | '' (nao configurado) */
    public static function credenciais(string $provedor): array
    {
        if (!self::existe($provedor)) {
            throw new \InvalidArgumentException('Provedor invalido.');
        }
        if (self::$carregadoEm < time() - self::CACHE_SEGUNDOS) {
            self::$cache = [];
            self::$carregadoEm = time();
        }
        return self::$cache[$provedor] ??= self::resolver($provedor);
    }

    public static function disponivel(string $provedor): bool
    {
        return self::credenciais($provedor)['origem'] !== '';
    }

    public static function clientId(string $provedor): string
    {
        return self::credenciais($provedor)['client_id'];
    }

    public static function clientSecret(string $provedor): string
    {
        return self::credenciais($provedor)['client_secret'];
    }

    /** Depois de salvar ou remover no painel. */
    public static function esquecer(): void
    {
        self::$cache = [];
        self::$carregadoEm = 0;
    }

    /** @return array{client_id:string, client_secret:string, origem:string} */
    private static function resolver(string $provedor): array
    {
        try {
            $salvo = (new AplicativoParceiroRepository(Database::connect()))->buscar($provedor);
            if ($salvo !== null && $salvo['client_id'] !== '' && $salvo['client_secret'] !== '') {
                return ['client_id' => $salvo['client_id'], 'client_secret' => $salvo['client_secret'], 'origem' => 'painel'];
            }
        } catch (\Throwable $exception) {
            // Banco fora ou chave de criptografia diferente: usa o .env (se houver) sem derrubar a tela.
            error_log('[Cardapim] Credenciais do app ' . $provedor . ' indisponiveis no banco: ' . get_class($exception));
        }
        $p = self::PROVEDORES[$provedor];
        $id = Config::get($p['env_id']);
        $segredo = Config::get($p['env_secret']);
        if ($id !== '' && $segredo !== '') {
            return ['client_id' => $id, 'client_secret' => $segredo, 'origem' => 'env'];
        }
        return ['client_id' => '', 'client_secret' => '', 'origem' => ''];
    }
}
