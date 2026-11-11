<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Gerencia\AssinaturaService;

/** Aba Assinatura: plano atual, faturas (pagar pelo Asaas), troca de plano, cancelamento e historico. */
final class AssinaturaController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $servico = $this->servico();
        $vigente = $servico->vigente();
        return $this->tela('gerencia/assinatura', 'assinatura', 'Assinatura', [
            'vigente' => $vigente,
            'faturas' => $servico->faturas(),
            'planos' => (new PlanoRepository($this->db))->listarPublicos(),
            'historico' => (new AssinaturaRepository($this->db))->historico($this->restaurante['id']),
            'bloqueado' => $this->restaurante['status'] === 'inadimplente',
            'pendente' => $this->restaurante['status'] === 'pendente',
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

    /** "Gerar cobranca" / "Atualizar faturas" (busca no Asaas da plataforma). */
    public function cobranca(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->gerarCobranca(), $this->url('assinatura'));
    }

    private function servico(): AssinaturaService
    {
        return new AssinaturaService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
