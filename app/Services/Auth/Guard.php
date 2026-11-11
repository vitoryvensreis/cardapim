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

    /**
     * Equipe logada (todos os cargos, menos cliente) fica no proprio painel: paginas publicas
     * (site, catalogo, cadastro, paginas legais e cardapios) mandam para a area do cargo.
     * Excecao: o gerente pode abrir o cardapio do proprio restaurante ("Ver meu cardapio").
     */
    public static function equipeNoPainel(?string $slugRestaurante = null): ?Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario === null || $usuario->cargo === 'cliente') {
            return null;
        }
        if ($slugRestaurante !== null && $usuario->cargo === 'gerente' && $usuario->restauranteSlug === $slugRestaurante) {
            return null;
        }
        return self::semCache(Response::redirect(Autorizacao::destino($usuario), 303));
    }

    /**
     * Envolve o handler de uma rota publica com equipeNoPainel() e responde sem cache.
     * $doRestaurante: o primeiro parametro da rota e o slug do restaurante.
     */
    public static function paginaPublica(callable|array $handler, bool $doRestaurante = false): \Closure
    {
        return static function (string ...$parametros) use ($handler, $doRestaurante): Response {
            $desvio = self::equipeNoPainel($doRestaurante ? ($parametros[0] ?? null) : null);
            if ($desvio !== null) {
                return $desvio;
            }
            if (is_array($handler) && is_string($handler[0])) {
                $handler = [new $handler[0](), $handler[1]];
            }
            // Sem cache: depois de entrar, o "voltar" do navegador pede a pagina de novo ao servidor
            // (que manda a equipe para o painel) em vez de mostrar a copia guardada.
            return $handler(...$parametros)->comCabecalhos(['Cache-Control' => 'no-store, private']);
        };
    }

    public static function proibido(UsuarioLogado $usuario, string $motivo = ''): Response
    {
        return self::semCache(Response::html(View::render('errors/403', [
            'pageTitle' => 'Acesso não permitido | ' . marca(),
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
