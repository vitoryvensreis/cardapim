<?php

declare(strict_types=1);

namespace Cardapim\Services\Descontos;

use Cardapim\Shared\Numero;
use PDO;

/**
 * Programa de fidelidade do restaurante (tabela restaurante_fidelidade).
 * Conta os pedidos CONCLUIDOS do cliente no cardapio online com itens a partir do valor minimo (0 = qualquer valor),
 * feitos depois da ultima vez que ele usou a recompensa. Chegou na quantidade: o proximo pedido ganha o desconto.
 * A recompensa usada (pedido com desconto_origem 'fidelidade' nao cancelado) zera a contagem.
 */
final class Fidelidade
{
    public const PADRAO = ['ativo' => false, 'compras_necessarias' => 10, 'tipo' => 'percentual', 'valor' => '10.00', 'valor_minimo' => '0.00'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{ativo:bool, compras_necessarias:int, tipo:string, valor:string, valor_minimo:string} */
    public function config(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT ativo, compras_necessarias, tipo, valor, valor_minimo FROM restaurante_fidelidade WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return self::PADRAO;
        }
        return [
            'ativo' => (int) $r['ativo'] === 1,
            'compras_necessarias' => (int) $r['compras_necessarias'],
            'tipo' => (string) $r['tipo'],
            'valor' => (string) $r['valor'],
            'valor_minimo' => (string) $r['valor_minimo'],
        ];
    }

    /**
     * Onde o cliente esta no cartao fidelidade. null = programa desligado.
     * @return array{compras:int, necessarias:int, faltam:int, ganhou:bool, recompensa:string, minimo:string, regra:string}|null
     */
    public function progresso(int $idRestaurante, int $idCliente): ?array
    {
        $c = $this->config($idRestaurante);
        if (!$c['ativo']) {
            return null;
        }
        $u = $this->db->prepare("SELECT COALESCE(MAX(id), 0) FROM pedidos WHERE id_restaurante = ? AND id_cliente = ? AND desconto_origem = 'fidelidade' AND status <> 'cancelado'");
        $u->execute([$idRestaurante, $idCliente]);
        $desde = (int) $u->fetchColumn();
        $q = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND id_cliente = ? AND status = 'concluido' AND id > ?
            AND (desconto_origem IS NULL OR desconto_origem <> 'fidelidade') AND CAST(subtotal AS DECIMAL(10,2)) >= CAST(? AS DECIMAL(10,2))");
        $q->execute([$idRestaurante, $idCliente, $desde, $c['valor_minimo']]);
        $feitas = (int) $q->fetchColumn();
        $n = $c['compras_necessarias'];
        return [
            'compras' => min($feitas, $n),
            'necessarias' => $n,
            'faltam' => max(0, $n - $feitas),
            'ganhou' => $feitas >= $n,
            'recompensa' => Desconto::rotulo($c['tipo'], $c['valor']),
            'minimo' => Numero::centavos($c['valor_minimo']) > 0 ? Numero::reais($c['valor_minimo']) : '',
            'regra' => self::regra($c),
        ];
    }

    /** Desconto da recompensa sobre os itens (centavos). */
    public function valorDesconto(int $idRestaurante, int $subtotal): int
    {
        $c = $this->config($idRestaurante);
        return Desconto::calcular($c['tipo'], $c['valor'], $subtotal);
    }

    /** "A cada 10 pedidos a partir de R$ 30,00, ganhe 10% de desconto no proximo." */
    public static function regra(array $c): string
    {
        $min = Numero::centavos((string) $c['valor_minimo']) > 0 ? ' a partir de ' . Numero::reais((string) $c['valor_minimo']) : '';
        $n = (int) $c['compras_necessarias'];
        return 'A cada ' . $n . ($n === 1 ? ' pedido' : ' pedidos') . $min . ', ganhe ' . Desconto::rotulo((string) $c['tipo'], (string) $c['valor']) . ' de desconto no próximo.';
    }
}
