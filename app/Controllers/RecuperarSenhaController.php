<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\RecuperarSenha;
use Throwable;

/**
 * "Esqueci minha senha": pedir o link (/recuperar-senha), abrir o link do e-mail (/recuperar-senha/{token}) e criar a
 * senha nova (/recuperar-senha/nova). O link sai da barra de endereco logo ao abrir (fica so na sessao), para nao
 * aparecer no historico nem ser enviado a outros sites.
 */
final class RecuperarSenhaController
{
    private const SESSAO = 'recuperar_senha_token';

    public function pedir(): Response
    {
        return $this->tela('auth/recuperar', ['enviado' => Session::flash('recuperar_enviado') === true]);
    }

    public function enviar(): Response
    {
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->tela('auth/recuperar', ['enviado' => false, 'erro' => 'Sua sessão expirou. Tente de novo.'], 419);
        }
        $identificacao = is_string($_POST['identificacao'] ?? null) ? $_POST['identificacao'] : '';
        if (trim($identificacao) === '') {
            return $this->tela('auth/recuperar', ['enviado' => false, 'erro' => 'Digite o e-mail ou o nome de usuário da sua conta.'], 422);
        }
        try {
            (new RecuperarSenha(Database::connect()))->solicitar($identificacao, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        } catch (Throwable $erro) {
            error_log('[Cardapim] Recuperar senha indisponivel: ' . get_class($erro));
            return $this->tela('auth/recuperar', ['enviado' => false, 'erro' => 'Não foi possível enviar agora. Tente de novo em instantes.'], 503);
        }
        Session::set('recuperar_enviado', true);
        return Response::redirect('/recuperar-senha', 303);
    }

    /** Link do e-mail: confere, guarda na sessao e tira da barra de endereco. */
    public function link(string $token): Response
    {
        try {
            $valido = (new RecuperarSenha(Database::connect()))->validar($token) !== null;
        } catch (Throwable) {
            $valido = false;
        }
        if (!$valido) {
            return $this->tela('auth/recuperar-nova', ['invalido' => true], 410);
        }
        Session::set(self::SESSAO, $token);
        return Response::redirect('/recuperar-senha/nova', 303);
    }

    public function nova(): Response
    {
        $conta = $this->contaDaSessao();
        if ($conta === null) {
            return $this->tela('auth/recuperar-nova', ['invalido' => true], 410);
        }
        return $this->tela('auth/recuperar-nova', ['invalido' => false, 'usuario' => (string) $conta['usuario'], 'erros' => []]);
    }

    public function salvar(): Response
    {
        $token = Session::get(self::SESSAO);
        if (!is_string($token) || !Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->tela('auth/recuperar-nova', ['invalido' => true], 410);
        }
        $servico = new RecuperarSenha(Database::connect());
        $conta = $servico->validar($token);
        $res = $servico->redefinir($token, (string) ($_POST['senha'] ?? ''), (string) ($_POST['senha_confirmacao'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$res->ok) {
            if ($res->erros === [] || $conta === null) {
                Session::remover(self::SESSAO);
                return $this->tela('auth/recuperar-nova', ['invalido' => true], 410);
            }
            return $this->tela('auth/recuperar-nova', ['invalido' => false, 'usuario' => (string) $conta['usuario'], 'erros' => $res->erros], 422);
        }
        Session::remover(self::SESSAO);
        Session::renovarId();
        return Response::redirect('/login?senha=alterada', 303);
    }

    /** @return array<string,mixed>|null */
    private function contaDaSessao(): ?array
    {
        $token = Session::get(self::SESSAO);
        if (!is_string($token)) {
            return null;
        }
        try {
            return (new RecuperarSenha(Database::connect()))->validar($token);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $dados */
    private function tela(string $view, array $dados, int $status = 200): Response
    {
        Session::iniciar();
        $html = View::render($view, [
            'pwa' => true,
            'pageTitle' => ($view === 'auth/recuperar' ? 'Esqueci minha senha' : 'Criar senha nova') . ' | ' . marca(),
            'pageDescription' => 'Redefinir a senha da sua conta ' . marca() . '.',
            'noIndex' => true,
            'pageScripts' => ['js/forms.js'],
            'csrf' => Session::tokenCsrf(),
            'erro' => null,
            ...$dados,
        ], 'layouts/public');
        return Guard::semCache(Response::html($html, $status))->comCabecalhos(['Referrer-Policy' => 'no-referrer']);
    }
}
