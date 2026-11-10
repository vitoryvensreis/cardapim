<?php

declare(strict_types=1);

namespace Cardapim\Services;

use Cardapim\Core\Database;
use PDO;
use RuntimeException;

final class Configuracoes
{
    private const PUBLIC_KEYS = [
        'logo_colorida',
        'logo_branca',
        'logo_preta',
        'icone',
        'nome_sistema',
        'descricao_padrao',
        'texto_rodape',
    ];

    private const LOGO_KEYS = ['logo_colorida', 'logo_branca', 'logo_preta', 'icone'];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /**
     * Apenas configuracoes publicas; credenciais continuam no .env.
     * Busca uma unica vez por requisicao.
     */
    public static function get(string $key): ?string
    {
        if (!in_array($key, self::PUBLIC_KEYS, true)) {
            throw new RuntimeException('Chave de configuracao nao permitida.');
        }

        if (self::$cache === null) {
            $db = Database::connect();
            $placeholders = implode(',', array_fill(0, count(self::PUBLIC_KEYS), '?'));
            $stmt = $db->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($placeholders)");
            $stmt->execute(self::PUBLIC_KEYS);
            self::$cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        return self::$cache[$key] ?? null;
    }

    public static function logo(string $key): string
    {
        if (!in_array($key, self::LOGO_KEYS, true)) {
            throw new RuntimeException('Logo nao permitida.');
        }
        $url = self::get($key);
        if ($url === null || $url === '') {
            throw new RuntimeException('Logo oficial nao configurada no banco.');
        }

        $local = str_starts_with($url, '/assets/img/')
            && !str_contains($url, '..')
            && preg_match('~\A/assets/img/[a-zA-Z0-9._/-]+\z~D', $url) === 1;
        $remote = str_starts_with($url, 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && !preg_match('/[\x00-\x1f\x7f]/', $url);
        if (!$local && !$remote) {
            throw new RuntimeException('URL de logo nao autorizada.');
        }
        return $url;
    }
}
