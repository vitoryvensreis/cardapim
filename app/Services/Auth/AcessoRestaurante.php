<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Shared\Slug;

/** Confere login + cargo + vinculo com o restaurante do slug. Usado por todas as areas do restaurante. */
final class AcessoRestaurante
{
    /** @return array{0:UsuarioLogado, 1:array{id:int,nome:string,slug:string,status:string}}|Response */
    public static function verificar(string $slug, string $area): array|Response
    {
        $usuario = Guard::exigirLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return Guard::proibido($usuario);
        }
        $restaurante = (new RestauranteRepository(Database::connect()))->buscarPorSlugParaEquipe($slug);
        if ($restaurante === null || !Autorizacao::podeAcessarRestaurante($usuario, $area, $restaurante['id'])) {
            // Mesma resposta para "nao existe" e "nao e seu": nao revela outros restaurantes.
            return Guard::proibido($usuario, 'Esta área não pertence ao seu cargo ou ao seu restaurante.');
        }
        return [$usuario, $restaurante];
    }
}
