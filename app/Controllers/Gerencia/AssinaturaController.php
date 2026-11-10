<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Gerencia\AssinaturaService;

/** Aba Assinatura: plano atual, troca de plano, cancelamento e historico. */
final class AssinaturaController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('gerencia/assinatura', 'assinatura', 'Assinatura', [
            'vigente' => $this->servico()->vigente(),
            'planos' => (new PlanoRepository($this->db))->listarPublicos(),
            'historico' => (new AssinaturaRepository($this->db))->historico($this->restaurante['id']),
        ]);
    }

    public function assinar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $planoId = ctype_digit($this->post('plano')) ? (int) $this->post('plano') : 0;
        return $this->responder($this->servico()->assinar($planoId), $this->url('assinatura'));
    }

    public function cancelar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->cancelar(), $this->url('assinatura'));
    }

    public function reativar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->reativar(), $this->url('assinatura'));
    }

    private function servico(): AssinaturaService
    {
        return new AssinaturaService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
