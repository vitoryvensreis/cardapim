<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Slug;

/**
 * Areas autenticadas por cargo. Nesta etapa as telas sao introdutorias;
 * o que importa e a autenticacao e o bloqueio por cargo e por restaurante.
 */
final class AreaController
{
    private const AREAS = [
        'gerencia' => ['Gerência', 'layout-dashboard', 'Controle completo do restaurante: pedidos, cardápio, equipe, estoque e financeiro.'],
        'caixa' => ['Caixa', 'wallet', 'Abertura e fechamento do caixa, vendas no balcão e pagamentos.'],
        'entregas' => ['Entregas', 'bike', 'Pedidos para entregar, rotas e atualização de status da entrega.'],
    ];

    public function painel(): Response
    {
        $usuario = Guard::exigirLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!Autorizacao::podeAcessarPainel($usuario)) {
            return Guard::proibido($usuario, 'O painel geral é exclusivo da administração do Cardapim.');
        }
        return $this->tela($usuario, 'area/painel', 'Painel geral', ['area' => 'painel']);
    }

    public function restaurante(string $slug, string $area): Response
    {
        $usuario = Guard::exigirLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!isset(self::AREAS[$area]) || !Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return Guard::proibido($usuario);
        }
        $restaurante = (new RestauranteRepository(Database::connect()))->buscarPorSlugParaEquipe($slug);
        if ($restaurante === null || !Autorizacao::podeAcessarRestaurante($usuario, $area, $restaurante['id'])) {
            // Mesma resposta para "nao existe" e "nao e seu": nao revela outros restaurantes.
            return Guard::proibido($usuario, 'Esta área não pertence ao seu cargo ou ao seu restaurante.');
        }
        [$titulo, $icone, $descricao] = self::AREAS[$area];
        return $this->tela($usuario, 'area/restaurante', $titulo . ' · ' . $restaurante['nome'], [
            'area' => $area,
            'titulo' => $titulo,
            'icone' => $icone,
            'descricao' => $descricao,
            'restaurante' => $restaurante,
        ]);
    }

    public function minhaConta(): Response
    {
        $usuario = Guard::exigirLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        $destino = Autorizacao::destino($usuario);
        if ($destino !== '/minha-conta') {
            return Response::redirect($destino);
        }
        return $this->tela($usuario, 'area/minha-conta', 'Minha conta', ['area' => 'minha-conta']);
    }

    private function tela(UsuarioLogado $usuario, string $view, string $titulo, array $dados): Response
    {
        return Guard::semCache(Response::html(View::render($view, [
            'pageTitle' => $titulo . ' | Cardapim',
            'pageDescription' => 'Área restrita do Cardapim.',
            'noIndex' => true,
            'usuario' => $usuario,
            'csrf' => Session::tokenCsrf(),
            ...$dados,
        ], 'layouts/app')));
    }
}
