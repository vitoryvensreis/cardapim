<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Services\Plataforma\AssinaturaCobranca;
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
        // Fatura atrasada alem da tolerancia: bloqueia na hora (sem esperar a rotina diaria).
        if ($restaurante['status'] !== 'inadimplente' && $usuario->cargo !== Autorizacao::CARGO_MASTER) {
            try {
                if ((new AssinaturaCobranca(Database::connect()))->conferirAtraso($restaurante['id'])) {
                    $restaurante['status'] = 'inadimplente';
                }
            } catch (\Throwable $exception) {
                error_log('[Cardapim] Conferencia de atraso indisponivel: ' . get_class($exception));
            }
        }
        if ($restaurante['status'] === 'inadimplente' && $usuario->cargo !== Autorizacao::CARGO_MASTER) {
            $caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $assinatura = '/' . $restaurante['slug'] . '/gerencia/assinatura';
            if ($area === 'gerencia' && $usuario->cargo === 'gerente') {
                if ($caminho !== $assinatura && !str_starts_with($caminho, $assinatura . '/')) {
                    Session::set('flash', ['tipo' => 'erro', 'mensagem' => 'O acesso está pausado por pagamento pendente. Pague a fatura abaixo para liberar tudo de novo.']);
                    return Guard::semCache(Response::redirect($assinatura, 303));
                }
            } else {
                return Guard::proibido($usuario, 'O acesso do restaurante está pausado por pagamento pendente. Peça ao gerente para regularizar a assinatura.');
            }
        }
        return [$usuario, $restaurante];
    }
}
