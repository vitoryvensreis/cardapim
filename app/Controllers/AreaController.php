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
use Cardapim\Services\Painel\MenuGerencia;
use Cardapim\Shared\Slug;

/**
 * Areas autenticadas por cargo. As telas ainda sao introdutorias ("em construcao");
 * o que esta pronto e a navegacao, as rotas e o bloqueio por cargo e por restaurante.
 */
final class AreaController
{
    /** /{slug}/gerencia (visao geral) e /{slug}/gerencia/{secao}. */
    public function gerencia(string $slug, string $secao = 'visao-geral'): Response
    {
        if ($secao !== 'visao-geral' && !MenuGerencia::existe($secao)) {
            return $this->naoEncontrado();
        }
        $acesso = $this->acessoRestaurante($slug, 'gerencia');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$usuario, $restaurante] = $acesso;
        // Mesas agora tem tela propria no modo caixa (cadastro de mesas).
        if ($secao === 'mesas') {
            return Response::redirect('/' . rawurlencode($restaurante['slug']) . '/caixa/mesas/gerenciar', 303);
        }
        $dados = MenuGerencia::SECOES[$secao];
        return $this->telaGerencia($usuario, $restaurante, $secao, $dados['titulo'], [
            'secao' => $dados,
            'saudacao' => $secao === 'visao-geral',
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

    /** @return array{0:UsuarioLogado, 1:array{id:int,nome:string,slug:string,status:string}}|Response */
    private function acessoRestaurante(string $slug, string $area): array|Response
    {
        $usuario = Guard::exigirLogin();
        if ($usuario instanceof Response) {
            return $usuario;
        }
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return Guard::proibido($usuario);
        }
        $restaurante = (new RestauranteRepository(Database::connect()))->buscarPorSlugParaEquipe($slug);
        if ($restaurante === null || !Autorizacao::podeAcessarRestaurante($usuario, $area, $restaurante['id'])) {
            // Mesma resposta para "nao existe" e "nao e seu": nao revela outros restaurantes.
            return Guard::proibido($usuario, 'Esta área não pertence ao seu cargo ou ao seu restaurante.');
        }
        return [$usuario, $restaurante];
    }

    private function telaGerencia(UsuarioLogado $usuario, array $restaurante, string $ativo, string $titulo, array $dados): Response
    {
        return Guard::semCache(Response::html(View::render('area/em-construcao', [
            'pageTitle' => $titulo . ' · ' . $restaurante['nome'] . ' | Cardapim',
            'usuario' => $usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $restaurante,
            'ativo' => $ativo,
            ...$dados,
        ], 'layouts/gerencia')));
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

    private function naoEncontrado(): Response
    {
        return Response::html(View::render('errors/404', [
            'pageTitle' => 'Página não encontrada | Cardapim',
            'pageDescription' => 'O endereço solicitado não foi encontrado.',
            'noIndex' => true,
        ], 'layouts/public'), 404);
    }
}
