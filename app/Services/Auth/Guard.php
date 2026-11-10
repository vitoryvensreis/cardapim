<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;

/** Protecao das rotas autenticadas. */
final class Guard
{
    /** Exige login; sem login guarda o destino e manda para /login. */
    public static function exigirLogin(): UsuarioLogado|Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario !== null) {
            return $usuario;
        }
        $destino = Autorizacao::caminhoLocalSeguro(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        if ($destino !== null) {
            Session::set('login_destino', $destino);
        }
        return Response::redirect('/login');
    }

    public static function proibido(UsuarioLogado $usuario, string $motivo = ''): Response
    {
        return self::semCache(Response::html(View::render('errors/403', [
            'pageTitle' => 'Acesso não permitido | Cardapim',
            'pageDescription' => 'Você não tem permissão para acessar esta área.',
            'noIndex' => true,
            'destino' => Autorizacao::destino($usuario),
            'motivo' => $motivo,
            'usuario' => $usuario,
            'csrf' => Session::tokenCsrf(),
            'area' => '',
        ], 'layouts/app'), 403));
    }

    /** Paginas com dados de conta nunca ficam em cache de navegador ou proxy. */
    public static function semCache(Response $response): Response
    {
        return $response->comCabecalhos([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
