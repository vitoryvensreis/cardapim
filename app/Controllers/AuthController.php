<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Auth\Autenticacao;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\Guard;
use Throwable;

final class AuthController
{
    public function login(): Response
    {
        $usuario = Autenticacao::usuario();
        if ($usuario !== null) {
            return Response::redirect(Autorizacao::destino($usuario));
        }
        return $this->formulario();
    }

    public function entrar(): Response
    {
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->formulario('Sua sessão expirou. Confira os dados e tente novamente.', 419);
        }
        $identificacao = is_string($_POST['identificacao'] ?? null) ? $_POST['identificacao'] : '';
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        try {
            $resultado = Autenticacao::entrar($identificacao, $senha, $ip, ($_POST['lembrar'] ?? '') === '1');
        } catch (Throwable $exception) {
            error_log('[Cardapim] Login indisponivel: ' . get_class($exception));
            return $this->formulario('Não foi possível entrar agora. Tente novamente em instantes.', 503, $identificacao);
        }
        if (!$resultado['ok']) {
            return $this->formulario($resultado['erro'], $resultado['status'], $identificacao, ($_POST['lembrar'] ?? '') === '1');
        }

        $usuario = $resultado['usuario'];
        // Volta para a pagina pedida antes do login, se o cargo tiver permissao; senao, area do cargo.
        $pedido = Autorizacao::caminhoLocalSeguro(Session::flash('login_destino'));
        $destino = Autorizacao::destino($usuario);
        if ($pedido !== null && $this->permitido($usuario, $pedido)) {
            $destino = $pedido;
        }
        return Response::redirect($destino, 303);
    }

    public function sair(): Response
    {
        if (Session::csrfValido($_POST['_csrf'] ?? null)) {
            Autenticacao::sair((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }
        return Response::redirect('/login?saiu=1', 303);
    }

    public function register(): Response
    {
        $id = filter_var($_GET['plano_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $plan = null;
        if ($id !== false && $id !== null) {
            $plan = (new PlanoRepository(Database::connect()))->buscarPublicoPorId($id);
        }

        return Response::html(View::render('auth/register', [
            'pageTitle' => 'Criar conta | Cardapim',
            'pageDescription' => 'Prepare seu cadastro no Cardapim.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'plano' => $plan,
            'planoInvalido' => $id !== null && $id !== false && $plan === null
                || isset($_GET['plano_id']) && ($id === false || $id === null),
        ], 'layouts/public'));
    }

    private function formulario(?string $erro = null, int $status = 200, string $identificacao = '', bool $lembrar = false): Response
    {
        Session::iniciar();
        $html = View::render('auth/login', [
            'pageTitle' => 'Entrar | Cardapim',
            'pageDescription' => 'Acesse sua conta Cardapim.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'csrf' => Session::tokenCsrf(),
            'erro' => $erro,
            'saiu' => $erro === null && isset($_GET['saiu']),
            'identificacao' => mb_substr($identificacao, 0, 190),
            'lembrar' => $lembrar,
        ], 'layouts/public');
        return Guard::semCache(Response::html($html, $status));
    }

    /** Confere se o caminho pedido pertence a uma area liberada para o usuario. */
    private function permitido(\Cardapim\Services\Auth\UsuarioLogado $usuario, string $caminho): bool
    {
        if ($caminho === '/painel') {
            return Autorizacao::podeAcessarPainel($usuario);
        }
        if (preg_match('~\A/([a-z0-9-]+)/(gerencia|caixa|entregas)\z~', $caminho, $m) === 1) {
            return $usuario->cargo === Autorizacao::CARGO_MASTER
                || ($usuario->restauranteSlug === $m[1] && in_array($usuario->cargo, Autorizacao::AREAS_RESTAURANTE[$m[2]], true));
        }
        return $caminho === '/minha-conta';
    }
}
