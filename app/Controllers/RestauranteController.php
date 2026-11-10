<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Shared\RestauranteLogo;
use Cardapim\Shared\Slug;

/**
 * Cardapio publico do restaurante: /{slug}. O cliente escolhe produtos, monta a sacola (guardada no
 * navegador e conferida no servidor) e entra pelo WhatsApp. Finalizar o pedido entra na proxima etapa.
 */
final class RestauranteController
{
    public function show(string $slug): Response
    {
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return $this->naoEncontrado();
        }
        try {
            $restaurante = (new RestauranteRepository(Database::connect()))->buscarPublicoPorSlug($slug);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Pagina do restaurante indisponivel: ' . get_class($exception));
            return Response::html(View::render('errors/500', [
                'pageTitle' => 'Erro temporário | Cardapim',
                'pageDescription' => 'Erro temporário no Cardapim.',
                'noIndex' => true,
            ], 'layouts/public'), 503);
        }
        if ($restaurante === null) {
            return $this->naoEncontrado();
        }

        try {
            $cardapio = new CardapioPublico(Database::connect());
            $menu = $cardapio->categorias($restaurante['id']);
            $info = $cardapio->informacoes($restaurante['id']);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Cardapio indisponivel: ' . get_class($exception));
            return Response::html(View::render('errors/500', [
                'pageTitle' => 'Erro temporário | Cardapim',
                'pageDescription' => 'Erro temporário no Cardapim.',
                'noIndex' => true,
            ], 'layouts/public'), 503);
        }

        return Response::html(View::render('restaurante/cardapio', [
            'pageTitle' => $restaurante['nome'] . ' · Cardápio | Cardapim',
            'pageDescription' => 'Veja o cardápio de ' . $restaurante['nome'] . ' e faça seu pedido pelo Cardapim.',
            'restaurante' => $restaurante,
            'logo' => RestauranteLogo::urlSegura($restaurante['logo_path']),
            'iniciais' => RestauranteLogo::iniciais($restaurante['nome']),
            'categorias' => $menu['categorias'],
            'totalProdutos' => $menu['total'],
            'info' => $info,
        ], 'layouts/cardapio'))->comCabecalhos(['Cache-Control' => 'no-cache']);
    }

    /** POST /api/v1/restaurantes/{slug}/sacola - confere precos e disponibilidade da sacola do navegador. */
    public function sacola(string $slug): Response
    {
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return Response::json(['erro' => 'Restaurante não encontrado.'], 404);
        }
        $corpo = (string) file_get_contents('php://input', false, null, 0, 16385);
        $dados = strlen($corpo) <= 16384 ? json_decode($corpo, true) : null;
        if (!is_array($dados) || !is_array($dados['itens'] ?? null)) {
            return Response::json(['erro' => 'Sacola inválida.'], 400);
        }
        try {
            $db = Database::connect();
            $restaurante = (new RestauranteRepository($db))->buscarPublicoPorSlug($slug);
            if ($restaurante === null) {
                return Response::json(['erro' => 'Restaurante não encontrado.'], 404);
            }
            $cardapio = new CardapioPublico($db);
            return Response::json([
                ...$cardapio->conferirSacola($restaurante['id'], $dados['itens']),
                'aberto' => $cardapio->informacoes($restaurante['id'])['aberto'],
            ]);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Sacola indisponivel: ' . get_class($exception));
            return Response::json(['erro' => 'Não foi possível conferir a sacola agora.'], 503);
        }
    }

    private function naoEncontrado(): Response
    {
        return Response::html(View::render('errors/404', [
            'pageTitle' => 'Página não encontrada | Cardapim',
            'pageDescription' => 'O endereço solicitado não foi encontrado.',
            'noIndex' => true,
        ], 'layouts/public'), 404);
    }
}
