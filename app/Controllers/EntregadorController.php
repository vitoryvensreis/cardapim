<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Realtime\TempoReal;
use Cardapim\Services\Auth\AcessoRestaurante;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Entregas\EntregadorService;

/**
 * Painel do entregador (/{slug}/entregas): entregas com ele, confirmar entrega, historico e localizacao em tempo real.
 * Gerente que abre /entregas vai para o Delivery do modo caixa (la ele despacha).
 */
final class EntregadorController
{
    public function painel(string $slug): Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'entregas');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        if ($usuario->cargo !== 'motoqueiro') {
            return Response::redirect('/' . rawurlencode($restaurante['slug']) . '/caixa/delivery', 303);
        }
        $s = new EntregadorService(Database::connect(), $restaurante['id'], $usuario);
        $flash = Session::flash('flash');
        return Guard::semCache(Response::html(View::render('entregador/painel', [
            'pageTitle' => 'Minhas entregas · ' . $restaurante['nome'] . ' | Cardapim',
            'pageDescription' => 'Painel do entregador.',
            'noIndex' => true,
            'usuario' => $usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $restaurante,
            'flash' => is_array($flash) ? $flash : null,
            'emRota' => $s->emRota(),
            'hoje' => $s->feitas(date('Y-m-d 00:00:00')),
            'resumo' => $s->resumo(),
            'posicao' => $s->posicao(),
            'tempoReal' => TempoReal::ativo() ? '/api/v1/tempo-real/ticket?restaurante=' . rawurlencode($restaurante['slug']) : null,
        ], 'layouts/entregador')));
    }

    public function entregue(string $slug, string $id): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        $volta = '/' . rawurlencode($restaurante['slug']) . '/entregas';
        if (!Session::csrfValido($_POST['_csrf'] ?? null)) {
            Session::set('flash', ['tipo' => 'erro', 'mensagem' => 'Sua sessão expirou. Tente de novo.']);
            return Response::redirect($volta, 303);
        }
        $r = ctype_digit($id) && strlen($id) <= 18
            ? (new EntregadorService(Database::connect(), $restaurante['id'], $usuario))->entregar((int) $id)
            : \Cardapim\Services\Gerencia\Resultado::falha('Entrega não encontrada.');
        Session::set('flash', ['tipo' => $r->ok ? 'sucesso' : 'erro', 'mensagem' => $r->mensagem]);
        return Response::redirect($volta, 303);
    }

    /** POST JSON {latitude, longitude, precisao, direcao, velocidade} com X-CSRF-Token. */
    public function localizacao(string $slug): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sessão expirada. Recarregue a página.'], 419);
        }
        if (!RateLimiter::padrao(40, 60)->permitir('posicao|' . $usuario->id)) {
            return Response::json(['ok' => false, 'mensagem' => 'Muitas atualizações seguidas.'], 429, ['Retry-After' => '30']);
        }
        $corpo = json_decode((string) file_get_contents('php://input', false, null, 0, 2048), true);
        if (!is_array($corpo)) {
            return Response::json(['ok' => false, 'mensagem' => 'Dados inválidos.'], 400);
        }
        $r = (new EntregadorService(Database::connect(), $restaurante['id'], $usuario))
            ->salvarPosicao($corpo['latitude'] ?? null, $corpo['longitude'] ?? null, $corpo['precisao'] ?? null, $corpo['direcao'] ?? null, $corpo['velocidade'] ?? null);
        return Response::json(['ok' => $r->ok, 'mensagem' => $r->mensagem], $r->ok ? 200 : 422, ['Cache-Control' => 'no-store']);
    }

    public function pararLocalizacao(string $slug): Response
    {
        $acesso = $this->motoqueiro($slug);
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null))) {
            return Response::json(['ok' => false], 419);
        }
        (new EntregadorService(Database::connect(), $restaurante['id'], $usuario))->pararPosicao();
        return Response::json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array{0:\Cardapim\Services\Auth\UsuarioLogado,1:array<string,mixed>}|Response */
    private function motoqueiro(string $slug): array|Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'entregas');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        if ($acesso[0]->cargo !== 'motoqueiro') {
            return Guard::proibido($acesso[0], 'Só o entregador usa esta ação.');
        }
        return $acesso;
    }
}
