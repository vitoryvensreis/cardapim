<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Caixa\MesaService;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Services\Gerencia\Resultado;

/** Comanda da mesa ou do balcao: lancar itens, cancelar item, ajustes, transferir, receber e cancelar. */
final class ComandaController extends BaseCaixa
{
    public function balcao(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = $this->servico()->abrirBalcao($this->post('nome'));
        return $this->responder($res, $res->id !== null ? $this->url('comandas/' . $res->id) : $this->url('mesas'), $this->url('mesas'));
    }

    public function ver(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $c = self::id($id) !== null ? $this->servico()->detalhes((int) $id) : null;
        if ($c === null) {
            return $this->voltar($this->url('mesas'), 'erro', 'Comanda não encontrada.');
        }
        $livres = [];
        if ($c['aberta']) {
            foreach ((new MesaService($this->db, $this->restaurante['id'], $this->usuario))->listar() as $m) {
                if ((int) $m['ativo'] === 1 && $m['comanda_id'] === null) {
                    $livres[] = $m;
                }
            }
        }
        return $this->tela('caixa/comanda', 'mesas', $c['rotulo'], [
            'c' => $c,
            'cardapio' => $c['aberta'] ? (new CardapioPublico($this->db))->categorias($this->restaurante['id']) : ['categorias' => [], 'total' => 0],
            'livres' => $livres,
            'caixaAberto' => (new CaixaRepository($this->db))->aberto($this->restaurante['id']) !== null,
        ]);
    }

    public function lancar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $produto = self::id($this->post('produto'));
        $qtd = ctype_digit($this->post('quantidade')) ? (int) $this->post('quantidade') : 1;
        $res = self::id($id) !== null && $produto !== null
            ? $this->servico()->lancar((int) $id, $produto, $qtd, $this->post('observacao'))
            : Resultado::falha('Escolha um produto.');
        return $this->responder($res, $this->url('comandas/' . (int) $id));
    }

    public function cancelarItem(string $slug, string $id, string $item): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null && self::id($item) !== null
            ? $this->servico()->cancelarItem((int) $id, (int) $item, $this->post('motivo'))
            : Resultado::falha('Item não encontrado.');
        return $this->responder($res, $this->url('comandas/' . (int) $id));
    }

    public function ajustar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null
            ? $this->servico()->ajustar((int) $id, ($_POST['servico'] ?? '') === '1', $this->post('desconto'), $this->post('motivo_desconto'), $this->post('pessoas'))
            : Resultado::falha('Comanda não encontrada.');
        $volta = $this->origem($this->url('comandas/' . (int) $id));
        return $this->responder($res, $volta);
    }

    public function transferir(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $mesa = self::id($this->post('mesa'));
        $res = self::id($id) !== null && $mesa !== null
            ? $this->servico()->transferir((int) $id, $mesa)
            : Resultado::falha('Escolha a mesa de destino.');
        return $this->responder($res, $this->url('comandas/' . (int) $id));
    }

    public function pagarForm(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $c = self::id($id) !== null ? $this->servico()->detalhes((int) $id) : null;
        if ($c === null) {
            return $this->voltar($this->url('mesas'), 'erro', 'Comanda não encontrada.');
        }
        if (!$c['aberta']) {
            return $this->voltar($this->url('comandas/' . (int) $id), 'aviso', 'Esta conta já foi fechada.');
        }
        return $this->tela('caixa/pagar', 'mesas', 'Receber · ' . $c['rotulo'], [
            'c' => $c,
            'caixaAberto' => (new CaixaRepository($this->db))->aberto($this->restaurante['id']) !== null,
        ]);
    }

    public function pagamento(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (self::id($id) === null) {
            return $this->voltar($this->url('mesas'), 'erro', 'Comanda não encontrada.');
        }
        $res = $this->servico()->pagar((int) $id, $this->post('forma'), $this->post('valor'), $this->post('recebido'));
        $c = $this->servico()->detalhes((int) $id);
        $fechada = $c !== null && !$c['aberta'];
        return $this->responder($res, $fechada ? $this->url('comandas/' . (int) $id) : $this->url('comandas/' . (int) $id . '/pagar'), $this->url('comandas/' . (int) $id . '/pagar'));
    }

    public function removerPagamento(string $slug, string $id, string $pagamento): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null && self::id($pagamento) !== null
            ? $this->servico()->removerPagamento((int) $id, (int) $pagamento)
            : Resultado::falha('Pagamento não encontrado.');
        return $this->responder($res, $this->url('comandas/' . (int) $id . '/pagar'));
    }

    public function cancelar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null ? $this->servico()->cancelar((int) $id, $this->post('motivo')) : Resultado::falha('Comanda não encontrada.');
        return $this->responder($res, $this->url('mesas'), $this->url('comandas/' . (int) $id));
    }

    private function servico(): ComandaService
    {
        return new ComandaService($this->db, $this->restaurante['id'], $this->usuario);
    }
}
