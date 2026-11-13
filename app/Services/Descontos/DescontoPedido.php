<?php

declare(strict_types=1);

namespace Cardapim\Services\Descontos;

use PDO;

/**
 * Decide o desconto do pedido do cardapio online. Cupom e fidelidade NAO se somam:
 * - com cupom digitado e valido: vale o cupom (a recompensa da fidelidade fica guardada para o proximo pedido);
 * - sem cupom e com a recompensa da fidelidade ganha: vale a fidelidade, sozinha.
 */
final class DescontoPedido
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{ok:bool, mensagem?:string, centavos:int, origem:?string, rotulo:string, motivo:?string, id_cupom:?int, codigo:?string, fidelidade:?array<string,mixed>}
     */
    public function calcular(int $idRestaurante, ?int $idCliente, int $subtotal, string $cupom): array
    {
        $fid = $idCliente !== null ? (new Fidelidade($this->db))->progresso($idRestaurante, $idCliente) : null;
        $vazio = ['ok' => true, 'centavos' => 0, 'origem' => null, 'rotulo' => '', 'motivo' => null, 'id_cupom' => null, 'codigo' => null, 'fidelidade' => $fid];
        if (trim($cupom) !== '') {
            $v = (new Cupons($this->db))->validar($idRestaurante, $idCliente, $cupom, $subtotal);
            if (!$v['ok']) {
                return ['ok' => false, 'mensagem' => $v['mensagem']] + $vazio;
            }
            $codigo = (string) $v['cupom']['codigo'];
            return [
                'ok' => true,
                'centavos' => $v['centavos'],
                'origem' => 'cupom',
                'rotulo' => 'Cupom ' . $codigo . ' (' . $v['rotulo'] . ')',
                'motivo' => 'Cupom ' . $codigo,
                'id_cupom' => (int) $v['cupom']['id'],
                'codigo' => $codigo,
                'fidelidade' => $fid,
            ];
        }
        if ($fid !== null && $fid['ganhou'] && $idCliente !== null) {
            return [
                'ok' => true,
                'centavos' => (new Fidelidade($this->db))->valorDesconto($idRestaurante, $subtotal),
                'origem' => 'fidelidade',
                'rotulo' => 'Fidelidade (' . $fid['recompensa'] . ')',
                'motivo' => 'Fidelidade: ' . $fid['necessarias'] . ($fid['necessarias'] === 1 ? ' pedido' : ' pedidos'),
                'id_cupom' => null,
                'codigo' => null,
                'fidelidade' => $fid,
            ];
        }
        return $vazio;
    }
}
