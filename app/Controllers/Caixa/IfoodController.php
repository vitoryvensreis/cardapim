<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Services\Ifood\BotaoLojaIfood;

/** Modo caixa: botao "Abrir loja no iFood" do topo (JSON). Rotas em /{slug}/caixa/ifood/... */
final class IfoodController extends BaseCaixa
{
    public function situacao(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        return BotaoLojaIfood::situacao($this->db, $this->usuario, $this->restaurante['id']);
    }

    public function abrir(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        return BotaoLojaIfood::abrir($this->db, $this->usuario, $this->restaurante['id']);
    }
}
