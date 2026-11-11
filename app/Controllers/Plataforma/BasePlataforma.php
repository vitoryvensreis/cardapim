<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/**
 * Base das telas do painel da PLATAFORMA (/painel/...): so o adm_master entra (conferido no servidor
 * a cada requisicao), CSRF nos POST, mensagens (flash) e render com o menu lateral da administracao.
 */
abstract class BasePlataforma
{
    protected UsuarioLogado $usuario;
    protected PDO $db;

    /** @return Response|null null = acesso liberado */
    protected function entrar(bool $exigirCsrf = false): ?Response
    {
        $usuario = Guard::exigirLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!Autorizacao::podeAcessarPainel($usuario)) {
            return Guard::proibido($usuario, 'O painel geral é exclusivo da administração do ' . marca() . '.');
        }
        $this->usuario = $usuario;
        $this->db = Database::connect();
        Auditoria::noRestaurante('Plataforma');
        if ($exigirCsrf && !Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->voltar('/painel', 'erro', 'Sua sessão expirou. Tente de novo.');
        }
        return null;
    }

    protected function voltar(string $url, string $tipo, string $mensagem): Response
    {
        Session::set('flash', ['tipo' => $tipo, 'mensagem' => $mensagem]);
        return Response::redirect($url, 303);
    }

    protected function responder(Resultado $r, string $url): Response
    {
        return $this->voltar($url, $r->ok ? 'sucesso' : 'erro', $r->mensagem);
    }

    /** @param array<string,mixed> $dados */
    protected function tela(string $view, string $ativo, string $titulo, array $dados = [], int $status = 200): Response
    {
        $flash = Session::flash('flash');
        return Guard::semCache(Response::html(View::render($view, [
            'pageTitle' => $titulo . ' · Plataforma | ' . marca(),
            'usuario' => $this->usuario,
            'csrf' => Session::tokenCsrf(),
            'ativo' => $ativo,
            'flash' => is_array($flash) ? $flash : null,
            'base' => '/painel',
            ...$dados,
        ], 'layouts/plataforma'), $status));
    }

    protected function post(string $campo): string
    {
        $valor = $_POST[$campo] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }
}
