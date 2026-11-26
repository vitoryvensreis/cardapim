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
        $destino = $this->destino($idPedido);
        if ($idPedido === null || FiscalEmissao::pronto($this->db, $this->restaurante['id']) === null) {
            return $this->voltar($this->url('mesas'), 'erro', 'A emissão de nota fiscal não está ligada para este restaurante.');
        }
        return FiscalEmissao::reenviar($this->db, $this->restaurante['id'], $idPedido)
            ? $this->voltar($destino, 'sucesso', 'Nota enviada de novo para a fila. Ela sai em alguns segundos.')
            : $this->voltar($destino, 'aviso', 'Esta nota não precisa ser reenviada agora.');
    }

    /** Imprime de novo o DANFE da nota autorizada da venda. */
    public function imprimir(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idPedido = self::id($id);
        $destino = $this->destino($idPedido);
        $nota = $idPedido !== null ? FiscalEmissao::situacao($this->db, $this->restaurante['id'], $idPedido) : null;
        if ($nota === null || $nota['status'] !== 'autorizada') {
            return $this->voltar($destino, 'erro', 'Só é possível imprimir uma nota autorizada.');
        }
        $res = \Cardapim\Services\Impressao\DanfeNfce::enfileirar($this->db, $this->restaurante['id'], (int) $nota['id'], $this->usuario->id, false);
        return $this->responder($res, $destino);
    }

    private function destino(?int $idPedido): string
    {
        return match ($this->post('voltar')) {
            'delivery' => $this->url('delivery/' . (int) $idPedido),
            'historico' => $this->url('comandas/' . (int) $idPedido . '/detalhes'),
            default => $this->url('comandas/' . (int) $idPedido),
        };
    }
}
