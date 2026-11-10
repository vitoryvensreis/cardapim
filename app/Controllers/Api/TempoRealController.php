<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Api;

use Cardapim\Core\Database;
use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Realtime\Canais;
use Cardapim\Realtime\TempoReal;
use Cardapim\Realtime\Ticket;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Services\Auth\Autenticacao;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Shared\Slug;
use Throwable;

/**
 * GET /api/v1/tempo-real/ticket?restaurante={slug}
 * Entrega um ticket de 60 s (uso unico) para o navegador abrir o WebSocket do restaurante.
 * Exige login; a equipe so recebe ticket do proprio restaurante (o adm_master, de qualquer um).
 */
final class TempoRealController
{
    public function ticket(): Response
    {
        if (!TempoReal::ativo()) {
            return Response::json(['erro' => 'Tempo real ainda não foi ligado neste servidor.'], 503);
        }
        try {
            $usuario = Autenticacao::usuario();
        } catch (Throwable $erro) {
            error_log('[Cardapim] Ticket do tempo real indisponivel: ' . get_class($erro));
            return Response::json(['erro' => 'Não foi possível conferir o login agora.'], 503);
        }
        if ($usuario === null) {
            return Response::json(['erro' => 'Entre na sua conta para receber atualizações.'], 401);
        }
        if (!RateLimiter::padrao(30, 60)->permitir('tempo-real|' . $usuario->id)) {
            return Response::json(['erro' => 'Muitas tentativas de conexão. Aguarde um minuto.'], 429, ['Retry-After' => '60']);
        }
        $slug = is_string($_GET['restaurante'] ?? null) ? $_GET['restaurante'] : '';
        if (!Slug::formatoValido($slug) || Slug::reservado($slug) || Canais::permitidos($usuario->cargo) === []) {
            return Response::json(['erro' => 'Sem acesso a este restaurante.'], 403);
        }
        try {
            $restaurante = (new RestauranteRepository(Database::connect()))->buscarPorSlugParaEquipe($slug);
        } catch (Throwable $erro) {
            error_log('[Cardapim] Ticket do tempo real indisponivel: ' . get_class($erro));
            return Response::json(['erro' => 'Não foi possível conferir o restaurante agora.'], 503);
        }
        $liberado = $restaurante !== null && ($usuario->cargo === Autorizacao::CARGO_MASTER
            || ($usuario->idRestaurante === $restaurante['id'] && in_array($usuario->restauranteStatus, Autorizacao::STATUS_COM_ACESSO, true)));
        if (!$liberado) {
            // Mesma resposta para "nao existe" e "nao e seu".
            return Response::json(['erro' => 'Sem acesso a este restaurante.'], 403);
        }
        return Response::json([
            'ticket' => Ticket::emitir($usuario->id, $restaurante['id'], $usuario->cargo),
            'url' => TempoReal::urlPublica(),
            'canais' => Canais::permitidos($usuario->cargo),
            'expira_em' => Ticket::VALIDADE,
        ]);
    }
}
