<?php

declare(strict_types=1);

namespace Cardapim\Services;

use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Shared\RestauranteLogo;
use PDO;

/** Monta as paginas do catalogo publico (mesmo formato para a view e para a API). */
final class Catalogo
{
    public const POR_PAGINA = 12;

    public function __construct(private readonly RestauranteRepository $restaurantes)
    {
    }

    public static function comBanco(PDO $db): self
    {
        return new self(new RestauranteRepository($db));
    }

    /**
     * @return array{itens: list<array{nome:string,slug:string,url:string,logo:?string,iniciais:string}>, proximo_cursor: ?string}
     * @throws \InvalidArgumentException quando o cursor foi adulterado
     */
    public function pagina(?string $cursor, string $busca = ''): array
    {
        $posicao = null;
        if ($cursor !== null && $cursor !== '') {
            $posicao = CatalogoCursor::decodificar($cursor);
            if ($posicao === null) {
                throw new \InvalidArgumentException('Cursor invalido.');
            }
        }
        $busca = mb_substr(trim(preg_replace('/\s+/u', ' ', $busca) ?? ''), 0, 80);
        $resultado = $this->restaurantes->listarCatalogo($posicao, self::POR_PAGINA, $busca);

        return [
            'itens' => array_map(static fn (array $r): array => [
                'nome' => $r['nome'],
                'slug' => $r['slug'],
                'url' => '/' . rawurlencode($r['slug']),
                'logo' => RestauranteLogo::urlSegura($r['logo_path']),
                'iniciais' => RestauranteLogo::iniciais($r['nome']),
            ], $resultado['itens']),
            'proximo_cursor' => $resultado['proximo'] !== null ? CatalogoCursor::codificar($resultado['proximo']) : null,
        ];
    }
}
