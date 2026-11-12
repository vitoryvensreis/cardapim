<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Caixa\DeliveryService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\DadosPedidoIfood;
use Cardapim\Services\Ifood\IfoodConta;

/** Pedidos do cardapio online no caixa: fila, ver o pedido, aceitar, pronto, despachar, entregue e cancelar. */
final class DeliveryController extends BaseCaixa
{
    public function lista(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $s = $this->servico();
        return $this->tela('caixa/delivery', 'delivery', 'Delivery', ['fila' => $s->fila(), 'recentes' => $s->recentes()]);
    }

    /** Pedaco do menu lateral (fila) para atualizar sem recarregar a pagina. */
    public function fila(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        // A fila e consultada a cada 60 s: aproveita para tentar de novo os avisos de WhatsApp que nao foram.
        \Cardapim\Services\Pedidos\AvisosWhatsApp::agendarEnvio((int) $this->restaurante['id']);
        $html = View::render('caixa/_fila', ['filaDelivery' => $this->servico()->fila(), 'slug' => $this->restaurante['slug']], 'layouts/fragmento');
        return Guard::semCache(Response::html($html));
    }

    public function ver(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $s = $this->servico();
        $d = self::id($id) !== null ? $s->detalhes((int) $id) : null;
        if ($d === null) {
            return $this->voltar($this->url('delivery'), 'erro', 'Pedido não encontrado.');
        }
        return $this->tela('caixa/delivery-ver', 'delivery', 'Pedido #' . $d['pedido']['numero'], [
            'd' => $d,
            'entregadores' => $s->entregadores(),
            'caixaAberto' => (new CaixaRepository($this->db))->aberto($this->restaurante['id']) !== null,
            'gestor' => in_array($this->usuario->cargo, ['gerente', 'adm_master'], true),
            'ifood' => $d['pedido']['origem'] === 'ifood' ? [
                'dados' => DadosPedidoIfood::ler($d['pedido']['ifood_dados'] ?? null),
                'conectado' => (new IfoodConta($this->db, $this->restaurante['id']))->conectada(),
            ] : null,
        ]);
    }

    public function acao(string $slug, string $id, string $acao): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $pedido = self::id($id);
        $s = $this->servico();
        $res = match (true) {
            $pedido === null => Resultado::falha('Pedido não encontrado.'),
            in_array($acao, ['aceitar', 'pronto'], true) => $s->avancar($pedido, $acao),
            $acao === 'despachar' => $s->despachar($pedido, self::id($this->post('entregador')) ?? 0),
            $acao === 'concluir' => $s->concluir($pedido),
            $acao === 'cancelar' => $s->cancelar($pedido, $this->post('motivo'), $this->post('motivo_ifood')),
            default => Resultado::falha('Ação desconhecida.'),
        };
        return $this->responder($res, $this->url('delivery/' . (int) $pedido));
    }

    private function servico(): DeliveryService
    {
        return new DeliveryService($this->db, $this->restaurante['id'], $this->usuario);
    }
}
