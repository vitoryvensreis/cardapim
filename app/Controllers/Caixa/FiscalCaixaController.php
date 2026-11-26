<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Services\Fiscal\FiscalEmissao;

/** Ações do caixa sobre a nota fiscal de uma venda. Com o módulo fiscal desligado não há nada a fazer aqui. */
final class FiscalCaixaController extends BaseCaixa
{
    public function reenviar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idPedido = self::id($id);
        $destino = match ($this->post('voltar')) {
            'delivery' => $this->url('delivery/' . (int) $idPedido),
            'historico' => $this->url('comandas/' . (int) $idPedido . '/detalhes'),
            default => $this->url('comandas/' . (int) $idPedido),
        };
        if ($idPedido === null || FiscalEmissao::pronto($this->db, $this->restaurante['id']) === null) {
            return $this->voltar($this->url('mesas'), 'erro', 'A emissão de nota fiscal não está ligada para este restaurante.');
        }
        return FiscalEmissao::reenviar($this->db, $this->restaurante['id'], $idPedido)
            ? $this->voltar($destino, 'sucesso', 'Nota enviada de novo para a fila. Ela sai em alguns segundos.')
            : $this->voltar($destino, 'aviso', 'Esta nota não precisa ser reenviada agora.');
    }
}
