<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Services\Caixa\HistoricoComandas;

/** Modo caixa > Comandas: lista do dia/semana/mes (abertas, fechadas, canceladas) e o detalhe de cada uma. */
final class HistoricoComandasController extends BaseCaixa
{
    public function lista(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $periodo = is_string($_GET['periodo'] ?? null) && isset(HistoricoComandas::PERIODOS[$_GET['periodo']]) ? $_GET['periodo'] : 'hoje';
        $status = is_string($_GET['status'] ?? null) && isset(HistoricoComandas::STATUS[$_GET['status']]) ? $_GET['status'] : 'todas';
        return $this->tela('caixa/comandas-historico', 'comandas', 'Comandas', [
            'periodo' => $periodo,
            'status' => $status,
            'lista' => (new HistoricoComandas($this->db, $this->restaurante['id']))->listar($periodo, $status),
        ]);
    }

    public function ver(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $num = ctype_digit($id) && strlen($id) <= 18 ? (int) $id : 0;
        $d = $num > 0 ? (new HistoricoComandas($this->db, $this->restaurante['id']))->detalhe($num, $this->usuario) : null;
        if ($d === null) {
            return $this->voltar($this->url('comandas'), 'erro', 'Comanda não encontrada.');
        }
        return $this->tela('caixa/comandas-historico-ver', 'comandas', $d['rotulo'] . ' · Pedido #' . \Cardapim\Services\Pedidos\NumeroDoDia::de($d['pedido']), ['d' => $d,
            'notaFiscal' => \Cardapim\Services\Fiscal\FiscalEmissao::situacao($this->db, $this->restaurante['id'], (int) $d['pedido']['id'])]);
    }
}
