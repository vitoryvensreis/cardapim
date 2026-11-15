<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Realtime\Canais;
use Cardapim\Realtime\TempoReal;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Cozinha\PainelCozinha;

/** Modo cozinha (KDS): tela exclusiva de leitura para o gerente. */
final class CozinhaController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($resposta = $this->entrar($slug)) {
            return $resposta;
        }

        return Guard::semCache(Response::html(View::render('gerencia/cozinha/index', [
            'pageTitle' => 'Cozinha · ' . $this->restaurante['nome'] . ' | ' . marca(),
            'restaurante' => $this->restaurante,
            'base' => $this->url(),
            'fila' => $this->dados(),
            'tempoReal' => TempoReal::ativo()
                ? ['ticket' => '/api/v1/tempo-real/ticket?restaurante=' . rawurlencode($slug),
                   'canais' => implode(',', Canais::daSecao('cozinha'))]
                : null,
        ], 'layouts/cozinha')));
    }

    /** Fragmento HTML autenticado para atualizar os cards, sem ações de escrita. */
    public function conteudo(string $slug): Response
    {
        if ($resposta = $this->entrar($slug)) {
            return $resposta;
        }

        return Guard::semCache(Response::html(View::render('gerencia/cozinha/_pedidos', [
            'fila' => $this->dados(),
        ], 'layouts/fragmento')));
    }

    /** @return array{pedidos:list<array<string,mixed>>,total:int} */
    private function dados(): array
    {
        return (new PainelCozinha($this->db, (int) $this->restaurante['id']))->painel();
    }
}
