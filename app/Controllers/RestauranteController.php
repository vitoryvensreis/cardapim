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
 * navegador e conferida no servidor), entra pelo WhatsApp, finaliza em /{slug}/finalizar e acompanha em /{slug}/pedido/{id}.
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
                'pageTitle' => 'Erro temporário | ' . marca(),
                'pageDescription' => 'Erro temporário no ' . marca() . '.',
                'noIndex' => true,
            ], 'layouts/public'), 503);
        }
        if ($restaurante === null || $this->bloqueado((int) $restaurante['id'])) {
            return $this->naoEncontrado();
        }

        try {
            $cardapio = new CardapioPublico(Database::connect());
            $menu = $cardapio->categorias($restaurante['id']);
            $info = $cardapio->informacoes($restaurante['id']);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Cardapio indisponivel: ' . get_class($exception));
            return Response::html(View::render('errors/500', [
                'pageTitle' => 'Erro temporário | ' . marca(),
                'pageDescription' => 'Erro temporário no ' . marca() . '.',
                'noIndex' => true,
            ], 'layouts/public'), 503);
        }

        return Response::html(View::render('restaurante/cardapio', [
            'pageTitle' => $restaurante['nome'] . ' · Cardápio | ' . marca(),
            'pageDescription' => 'Veja o cardápio de ' . $restaurante['nome'] . ' e faça seu pedido pelo ' . marca() . '.',
            'restaurante' => $restaurante,
            'logo' => RestauranteLogo::urlSegura($restaurante['logo_path']),
            'iniciais' => RestauranteLogo::iniciais($restaurante['nome']),
            'categorias' => $menu['categorias'],
            'totalProdutos' => $menu['total'],
            'info' => $info,
        ], 'layouts/cardapio'))->comCabecalhos(['Cache-Control' => 'no-cache']);
    }

    /** GET /{slug}/finalizar - entrega ou retirada, endereco, pagamento e resumo (a sacola vem do aparelho). */
    public function finalizar(string $slug): Response
    {
        $restaurante = $this->restaurantePublico($slug);
        if ($restaurante instanceof Response) {
            return $restaurante;
        }
        try {
            $config = (new \Cardapim\Services\Pedidos\CheckoutPublico(Database::connect()))->config($restaurante['id']);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Finalizar pedido indisponivel: ' . get_class($exception));
            return $this->erroTemporario();
        }
        return Response::html(View::render('restaurante/finalizar', [
            'pageTitle' => 'Finalizar pedido · ' . $restaurante['nome'] . ' | ' . marca(),
            'pageDescription' => 'Finalize seu pedido em ' . $restaurante['nome'] . '.',
            'noIndex' => true,
            'restaurante' => $restaurante,
            'config' => $config,
            'voltar' => ['/' . $restaurante['slug'], 'Voltar ao cardápio'],
            'topoFixo' => true,
            'pageScripts' => ['js/checkout.js'],
            'pageStyles' => ['css/checkout.css'],
        ], 'layouts/cardapio'))->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    /** GET /{slug}/pedido/{id} - acompanhamento do pedido (dados carregados pela API, so para o dono). */
    public function pedido(string $slug, string $id): Response
    {
        $restaurante = $this->restaurantePublico($slug);
        if ($restaurante instanceof Response) {
            return $restaurante;
        }
        if (!ctype_digit($id) || strlen($id) > 18) {
            return $this->naoEncontrado();
        }
        $telefone = '';
        try {
            $telefone = (new CardapioPublico(Database::connect()))->informacoes($restaurante['id'])['telefone'];
        } catch (\Throwable) {
        }
        return Response::html(View::render('restaurante/pedido', [
            'pageTitle' => 'Seu pedido · ' . $restaurante['nome'] . ' | ' . marca(),
            'pageDescription' => 'Acompanhe seu pedido em ' . $restaurante['nome'] . '.',
            'noIndex' => true,
            'restaurante' => $restaurante,
            'idPedido' => (int) $id,
            'telefone' => $telefone,
            'voltar' => ['/' . $restaurante['slug'], 'Voltar ao cardápio'],
            'topoFixo' => true,
            'pageScripts' => ['vendor/qrcode-generator/qrcode.js', 'vendor/leaflet/leaflet.js', 'js/acompanhar.js'],
            'pageStyles' => ['vendor/leaflet/leaflet.css', 'css/checkout.css'],
        ], 'layouts/cardapio'))->comCabecalhos(['Cache-Control' => 'no-store']);
    }

    /** @return array{id:int,nome:string,slug:string,logo_path:?string}|Response */
    private function restaurantePublico(string $slug): array|Response
    {
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return $this->naoEncontrado();
        }
        try {
            $restaurante = (new RestauranteRepository(Database::connect()))->buscarPublicoPorSlug($slug);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Restaurante indisponivel: ' . get_class($exception));
            return $this->erroTemporario();
        }
        return $restaurante !== null && !$this->bloqueado((int) $restaurante['id']) ? $restaurante : $this->naoEncontrado();
    }

    /** Assinatura com fatura atrasada alem da tolerancia: o cardapio sai do ar ate o pagamento. */
    private function bloqueado(int $idRestaurante): bool
    {
        try {
            return (new \Cardapim\Services\Plataforma\AssinaturaCobranca(Database::connect()))->conferirAtraso($idRestaurante);
        } catch (\Throwable $exception) {
            error_log('[Cardapim] Conferencia de atraso indisponivel: ' . get_class($exception));
            return false;
        }
    }

    private function erroTemporario(): Response
    {
        return Response::html(View::render('errors/500', [
            'pageTitle' => 'Erro temporário | ' . marca(),
            'pageDescription' => 'Erro temporário no ' . marca() . '.',
            'noIndex' => true,
        ], 'layouts/public'), 503);
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
            'pageTitle' => 'Página não encontrada | ' . marca(),
            'pageDescription' => 'O endereço solicitado não foi encontrado.',
            'noIndex' => true,
        ], 'layouts/public'), 404);
    }
}
