<?php

declare(strict_types=1);

namespace Cardapim\Services\Descontos;

use Cardapim\Shared\Numero;
use PDO;

/**
 * Cupons do restaurante (tabela cupons): conferencia na hora do pedido.
 * Usos contam pedidos com o cupom que nao foram cancelados (cancelou = o uso volta).
 */
final class Cupons
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Letras maiusculas, numeros e hifen (o cliente pode digitar com espacos ou minusculas). */
    public static function normalizar(string $codigo): string
    {
        return (string) preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($codigo)));
    }

    /** @return array<string,mixed>|null */
    public function porCodigo(int $idRestaurante, string $codigo): ?array
    {
        $q = $this->db->prepare('SELECT * FROM cupons WHERE id_restaurante = ? AND codigo = ?');
        $q->execute([$idRestaurante, $codigo]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        return $c === false ? null : $c;
    }

    public function usos(int $idRestaurante, int $idCupom, ?int $idCliente = null): int
    {
        $sql = "SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND id_cupom = ? AND status <> 'cancelado'";
        $p = [$idRestaurante, $idCupom];
        if ($idCliente !== null) {
            $sql .= ' AND id_cliente = ?';
            $p[] = $idCliente;
        }
        $q = $this->db->prepare($sql);
        $q->execute($p);
        return (int) $q->fetchColumn();
    }

    /**
     * Confere o cupom para este cliente e este valor de itens.
     * @return array{ok:true, cupom:array<string,mixed>, centavos:int, rotulo:string}|array{ok:false, mensagem:string}
     */
    public function validar(int $idRestaurante, ?int $idCliente, string $texto, int $subtotal): array
    {
        $codigo = self::normalizar($texto);
        if (strlen($codigo) < 3) {
            return ['ok' => false, 'mensagem' => 'Digite o código do cupom.'];
        }
        $c = $this->porCodigo($idRestaurante, $codigo);
        if ($c === null || (int) $c['ativo'] !== 1) {
            return ['ok' => false, 'mensagem' => 'Cupom não encontrado. Confira as letras e os números.'];
        }
        if ($c['valido_ate'] !== null && (string) $c['valido_ate'] < date('Y-m-d')) {
            return ['ok' => false, 'mensagem' => 'Este cupom venceu em ' . date('d/m/Y', (int) strtotime((string) $c['valido_ate'])) . '.'];
        }
        $minimo = Numero::centavos((string) $c['pedido_minimo']);
        if ($minimo > 0 && $subtotal < $minimo) {
            return ['ok' => false, 'mensagem' => 'Este cupom vale para pedidos a partir de ' . Numero::reais((string) $c['pedido_minimo']) . ' em itens. Faltam ' . Numero::reais(Desconto::dec($minimo - $subtotal)) . '.'];
        }
        if ($c['limite_usos'] !== null && $this->usos($idRestaurante, (int) $c['id']) >= (int) $c['limite_usos']) {
            return ['ok' => false, 'mensagem' => 'Este cupom já foi usado o máximo de vezes.'];
        }
        if ((int) $c['um_por_cliente'] === 1 && $idCliente !== null && $this->usos($idRestaurante, (int) $c['id'], $idCliente) > 0) {
            return ['ok' => false, 'mensagem' => 'Você já usou este cupom.'];
        }
        return [
            'ok' => true,
            'cupom' => $c,
            'centavos' => Desconto::calcular((string) $c['tipo'], (string) $c['valor'], $subtotal),
            'rotulo' => Desconto::rotulo((string) $c['tipo'], (string) $c['valor']),
        ];
    }
}
