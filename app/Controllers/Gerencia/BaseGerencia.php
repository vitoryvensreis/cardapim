<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Services\Auth\AcessoRestaurante;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Painel\MenuGerencia;
use PDO;

/**
 * Base das abas do painel do gerente: acesso, CSRF, mensagens (flash), redirecionamentos e render.
 * Toda acao recebe o slug e passa por AcessoRestaurante (cargo + restaurante) antes de qualquer coisa.
 */
abstract class BaseGerencia
{
    protected UsuarioLogado $usuario;
    /** @var array{id:int,nome:string,slug:string,status:string} */
    protected array $restaurante;
    protected PDO $db;

    /** @return Response|null null = acesso liberado (propriedades preenchidas) */
    protected function entrar(string $slug, bool $exigirCsrf = false): ?Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'gerencia');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$this->usuario, $this->restaurante] = $acesso;
        $this->db = Database::connect();
        \Cardapim\Services\Auditoria::noRestaurante($this->restaurante['nome']);
        if ($exigirCsrf && !Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->voltar($this->origem($this->url()), 'erro', 'Sua sessão expirou. Tente de novo.');
        }
        return null;
    }

    protected function url(string $caminho = ''): string
    {
        return MenuGerencia::url($this->restaurante['slug'], 'visao-geral') . ($caminho !== '' ? '/' . ltrim($caminho, '/') : '');
    }

    /** Redireciona com mensagem para a proxima tela (padrao Post/Redirect/Get). */
    protected function voltar(string $url, string $tipo, string $mensagem): Response
    {
        Session::set('flash', ['tipo' => $tipo, 'mensagem' => $mensagem]);
        return Response::redirect($url, 303);
    }

    /** @param array<string,mixed> $dados */
    protected function tela(string $view, string $ativo, string $titulo, array $dados = [], int $status = 200): Response
    {
        $flash = Session::flash('flash');
        return Guard::semCache(Response::html(View::render($view, [
            'pageTitle' => $titulo . ' · ' . $this->restaurante['nome'] . ' | Cardapim',
            'usuario' => $this->usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $this->restaurante,
            'ativo' => $ativo,
            'flash' => is_array($flash) ? $flash : null,
            'base' => $this->url(),
            ...$dados,
        ], 'layouts/gerencia'), $status));
    }

    protected function post(string $campo): string
    {
        $valor = $_POST[$campo] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }

    protected function marcado(string $campo): bool
    {
        return ($_POST[$campo] ?? '') === '1';
    }

    /** Pagina anterior (somente caminhos do proprio painel); senao, o padrao informado. */
    protected function origem(string $padrao): string
    {
        $referer = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        $query = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
        if ($referer === '' || !str_starts_with($referer, $this->url()) || preg_match('~\A[a-z0-9/_-]+\z~', $referer) !== 1) {
            return $padrao;
        }
        return $referer . (preg_match('/\A[a-z0-9=&_-]{1,80}\z/', $query) === 1 ? '?' . $query : '');
    }

    /** Converte o {id} da rota; null se nao for numero positivo. */
    protected static function id(string $valor): ?int
    {
        return ctype_digit($valor) && strlen($valor) <= 18 && (int) $valor > 0 ? (int) $valor : null;
    }

    /** Resultado de acao: volta com mensagem de sucesso ou de erro. */
    protected function responder(\Cardapim\Services\Gerencia\Resultado $r, string $urlSucesso, ?string $urlErro = null): Response
    {
        return $this->voltar($r->ok ? $urlSucesso : ($urlErro ?? $urlSucesso), $r->ok ? 'sucesso' : 'erro', $r->mensagem);
    }

    protected function naoEncontrado(string $oque, string $url): Response
    {
        return $this->voltar($url, 'erro', $oque . ' não encontrado. Ele pode ter sido excluído.');
    }
}
