<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Services\Auth\AcessoRestaurante;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/**
 * Base das telas do modo caixa (caixa, gerente do restaurante ou adm_master).
 * Toda acao passa por AcessoRestaurante('caixa') antes de qualquer coisa; POST sempre com CSRF.
 */
abstract class BaseCaixa
{
    protected UsuarioLogado $usuario;
    /** @var array{id:int,nome:string,slug:string,status:string} */
    protected array $restaurante;
    protected PDO $db;

    protected function entrar(string $slug, bool $exigirCsrf = false): ?Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'caixa');
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
        return '/' . rawurlencode($this->restaurante['slug']) . '/caixa' . ($caminho !== '' ? '/' . ltrim($caminho, '/') : '');
    }

    protected function voltar(string $url, string $tipo, string $mensagem): Response
    {
        if ($mensagem !== '') {
            Session::set('flash', ['tipo' => $tipo, 'mensagem' => $mensagem]);
        }
        return Response::redirect($url, 303);
    }

    protected function responder(Resultado $r, string $urlSucesso, ?string $urlErro = null): Response
    {
        if (!$r->ok && $r->erros !== []) {
            Session::set('caixa_erros', $r->erros);
            Session::set('caixa_enviado', array_map(static fn ($v): string => is_string($v) ? mb_substr($v, 0, 300) : '', $_POST));
        }
        return $this->voltar($r->ok ? $urlSucesso : ($urlErro ?? $urlSucesso), $r->ok ? 'sucesso' : 'erro', $r->mensagem);
    }

    /** @param array<string,mixed> $dados */
    protected function tela(string $view, string $ativo, string $titulo, array $dados = [], int $status = 200): Response
    {
        $flash = Session::flash('flash');
        $erros = Session::flash('caixa_erros');
        $enviado = Session::flash('caixa_enviado');
        return Guard::semCache(Response::html(View::render($view, [
            'pageTitle' => $titulo . ' · Caixa · ' . $this->restaurante['nome'] . ' | Cardapim',
            'usuario' => $this->usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $this->restaurante,
            'ativo' => $ativo,
            'tituloTela' => $titulo,
            'flash' => is_array($flash) ? $flash : null,
            'erros' => is_array($erros) ? $erros : [],
            'enviado' => is_array($enviado) ? $enviado : [],
            'base' => $this->url(),
            // Barra lateral: situacao do caixa e fila de delivery (sempre visiveis no modo caixa).
            'caixaAtual' => (new \Cardapim\Repositories\Caixa\CaixaRepository($this->db))->aberto($this->restaurante['id']),
            'filaDelivery' => (new \Cardapim\Services\Caixa\DeliveryService($this->db, $this->restaurante['id'], $this->usuario))->fila(),
            ...$dados,
        ], 'layouts/caixa'), $status));
    }

    protected function post(string $campo): string
    {
        $valor = $_POST[$campo] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }

    protected function origem(string $padrao): string
    {
        $referer = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        if ($referer === '' || !str_starts_with($referer, $this->url()) || preg_match('~\A[a-z0-9/_-]+\z~', $referer) !== 1) {
            return $padrao;
        }
        return $referer;
    }

    protected static function id(string $valor): ?int
    {
        return ctype_digit($valor) && strlen($valor) <= 18 && (int) $valor > 0 ? (int) $valor : null;
    }
}
