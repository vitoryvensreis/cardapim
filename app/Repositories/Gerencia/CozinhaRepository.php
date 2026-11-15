<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/**
 * Leitura da fila da cozinha. Nunca altera pedidos e sempre limita os dados ao restaurante.
 */
final class CozinhaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function pedidos(int $idRestaurante): array
    {
        // "aberto" inclui comandas de mesa e balcão com itens lançados.
        // "pronto" sai da cozinha: não existe ação de status nesta tela.
        $consulta = $this->db->prepare(
            "SELECT p.id, p.numero, p.origem, p.tipo, p.status, p.criado_em,
                    p.cliente_nome, p.comanda_nome, p.observacao, p.ifood_display_id,
                    m.numero AS mesa_numero
             FROM pedidos p
             LEFT JOIN mesas m ON m.id = p.id_mesa AND m.id_restaurante = p.id_restaurante
             WHERE p.id_restaurante = ?
               AND p.status IN ('aberto', 'recebido', 'em_preparo')
               AND EXISTS (
                   SELECT 1 FROM pedido_itens i
                   WHERE i.id_pedido = p.id AND i.id_restaurante = p.id_restaurante
                     AND i.cancelado_em IS NULL
               )
             ORDER BY p.criado_em ASC, p.id ASC"
        );
        $consulta->execute([$idRestaurante]);
        $pedidos = $consulta->fetchAll(PDO::FETCH_ASSOC);
        if ($pedidos === []) {
            return [];
        }

        $porId = [];
        foreach ($pedidos as $indice => &$pedido) {
            $pedido['itens'] = [];
            $porId[(int) $pedido['id']] = $indice;
        }
        unset($pedido);

        // IDs vieram do SELECT acima, sempre do restaurante autenticado.
        $ids = array_keys($porId);
        // Divide os IN em blocos para não exceder limites de parâmetros em grandes filas.
        foreach (array_chunk($ids, 100) as $bloco) {
            $marcadores = implode(',', array_fill(0, count($bloco), '?'));
            $itens = $this->db->prepare(
                "SELECT id_pedido, nome, quantidade, unidade, observacao
                 FROM pedido_itens
                 WHERE id_restaurante = ? AND cancelado_em IS NULL
                   AND id_pedido IN ($marcadores)
                 ORDER BY id_pedido, criado_em, id"
            );
            $itens->execute([$idRestaurante, ...$bloco]);
            foreach ($itens->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $indice = $porId[(int) $item['id_pedido']];
                $pedidos[$indice]['itens'][] = $item;
            }
        }
        return $pedidos;
    }
}
