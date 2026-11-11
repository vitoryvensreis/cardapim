<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/**
 * Consultas da aba Financeiro. Todas filtram pelo restaurante e usam intervalo [de, ate) em texto 'Y-m-d H:i:s'
 * (mesmo fuso do sistema), para funcionar igual no MySQL e no SQLite dos testes.
 * Valores voltam como texto decimal; quem soma e o servico, em centavos.
 */
final class FinanceiroRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Pedidos concluidos no periodo. @return array{pedidos:int,total:string,taxa_entrega:string,desconto:string} */
    public function vendas(int $idRestaurante, string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(taxa_entrega), 0) AS taxa_entrega, COALESCE(SUM(desconto), 0) AS desconto
             FROM pedidos WHERE id_restaurante = ? AND status = 'concluido' AND concluido_em >= ? AND concluido_em < ?"
        );
        $q->execute([$idRestaurante, $de, $ate]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pedidos' => (int) ($r['pedidos'] ?? 0),
            'total' => (string) ($r['total'] ?? '0'),
            'taxa_entrega' => (string) ($r['taxa_entrega'] ?? '0'),
            'desconto' => (string) ($r['desconto'] ?? '0'),
        ];
    }

    /** Vendas concluidas por canal (origem). @return list<array{origem:string,pedidos:int,total:string}> */
    public function vendasPorOrigem(int $idRestaurante, string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT origem, COUNT(*) AS pedidos, SUM(total) AS total FROM pedidos
             WHERE id_restaurante = ? AND status = 'concluido' AND concluido_em >= ? AND concluido_em < ?
             GROUP BY origem"
        );
        $q->execute([$idRestaurante, $de, $ate]);
        return array_map(static fn (array $r): array => ['origem' => (string) $r['origem'], 'pedidos' => (int) $r['pedidos'], 'total' => (string) $r['total']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Vendas concluidas por dia. @return list<array{dia:string,pedidos:int,total:string}> */
    public function vendasPorDia(int $idRestaurante, string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT SUBSTR(concluido_em, 1, 10) AS dia, COUNT(*) AS pedidos, SUM(total) AS total FROM pedidos
             WHERE id_restaurante = ? AND status = 'concluido' AND concluido_em >= ? AND concluido_em < ?
             GROUP BY SUBSTR(concluido_em, 1, 10)"
        );
        $q->execute([$idRestaurante, $de, $ate]);
        return array_map(static fn (array $r): array => ['dia' => (string) $r['dia'], 'pedidos' => (int) $r['pedidos'], 'total' => (string) $r['total']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Dinheiro recebido de pedidos (pagamentos registrados), por forma e por dia. Pedido cancelado nao conta.
     * @return list<array{dia:string,forma:string,total:string}>
     */
    public function recebidoPorFormaEDia(int $idRestaurante, string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT SUBSTR(pg.criado_em, 1, 10) AS dia, pg.forma, SUM(pg.valor) AS total
             FROM pedido_pagamentos pg
             JOIN pedidos p ON p.id = pg.id_pedido AND p.id_restaurante = pg.id_restaurante
             WHERE pg.id_restaurante = ? AND pg.criado_em >= ? AND pg.criado_em < ? AND p.status <> 'cancelado'
             GROUP BY SUBSTR(pg.criado_em, 1, 10), pg.forma"
        );
        $q->execute([$idRestaurante, $de, $ate]);
        return array_map(static fn (array $r): array => ['dia' => (string) $r['dia'], 'forma' => (string) $r['forma'], 'total' => (string) $r['total']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Entradas e saidas registradas na gaveta do caixa. @return list<array<string,mixed>> */
    public function movimentosCaixa(int $idRestaurante, string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT m.id, m.id_caixa, m.tipo, m.categoria, m.descricao, m.valor, m.criado_em, CONCAT(u.nome, ' ', u.sobrenome) AS autor
             FROM caixa_movimentos m LEFT JOIN `user` u ON u.id = m.id_user
             WHERE m.id_restaurante = ? AND m.criado_em >= ? AND m.criado_em < ?
             ORDER BY m.criado_em DESC, m.id DESC LIMIT 2000"
        );
        $q->execute([$idRestaurante, $de, $ate]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Caixas abertos no periodo (fechamentos). @return list<array<string,mixed>> */
    public function caixas(int $idRestaurante, string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT c.id, c.status, c.aberto_em, c.fechado_em, c.valor_abertura, c.dinheiro_esperado, c.dinheiro_contado, c.diferenca, c.resumo,
                    CONCAT(ua.nome, ' ', ua.sobrenome) AS aberto_por, CONCAT(uf.nome, ' ', uf.sobrenome) AS fechado_por
             FROM caixas c
             LEFT JOIN `user` ua ON ua.id = c.id_user_abertura
             LEFT JOIN `user` uf ON uf.id = c.id_user_fechamento
             WHERE c.id_restaurante = ? AND c.aberto_em >= ? AND c.aberto_em < ?
             ORDER BY c.aberto_em DESC, c.id DESC LIMIT 400"
        );
        $q->execute([$idRestaurante, $de, $ate]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ---------- Contas a pagar e a receber ---------- */

    /** Contas pagas/recebidas no periodo (pela data da baixa). @return list<array<string,mixed>> */
    public function contasBaixadas(int $idRestaurante, string $diaDe, string $diaAte): array
    {
        $q = $this->db->prepare(
            "SELECT c.*, CONCAT(u.nome, ' ', u.sobrenome) AS autor FROM contas_financeiras c LEFT JOIN `user` u ON u.id = c.id_user
             WHERE c.id_restaurante = ? AND c.status = 'paga' AND c.pago_em >= ? AND c.pago_em <= ?
             ORDER BY c.pago_em DESC, c.id DESC LIMIT 2000"
        );
        $q->execute([$idRestaurante, $diaDe, $diaAte]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista da aba Contas. $situacao: abertas | vencidas | pagas | todas ; $tipo: pagar | receber | null.
     * @return list<array<string,mixed>>
     */
    public function contas(int $idRestaurante, string $situacao, ?string $tipo, string $hoje): array
    {
        $onde = ['c.id_restaurante = ?'];
        $params = [$idRestaurante];
        if ($tipo !== null) {
            $onde[] = 'c.tipo = ?';
            $params[] = $tipo;
        }
        $ordem = "CASE WHEN c.status = 'aberta' THEN 0 ELSE 1 END, CASE WHEN c.status = 'aberta' THEN c.vencimento END ASC, c.pago_em DESC, c.id DESC";
        if ($situacao === 'abertas') {
            $onde[] = "c.status = 'aberta'";
        } elseif ($situacao === 'vencidas') {
            $onde[] = "c.status = 'aberta' AND c.vencimento < ?";
            $params[] = $hoje;
        } elseif ($situacao === 'pagas') {
            $onde[] = "c.status = 'paga'";
            $ordem = 'c.pago_em DESC, c.id DESC';
        }
        $q = $this->db->prepare(
            "SELECT c.*, CONCAT(u.nome, ' ', u.sobrenome) AS autor FROM contas_financeiras c LEFT JOIN `user` u ON u.id = c.id_user
             WHERE " . implode(' AND ', $onde) . ' ORDER BY ' . $ordem . ' LIMIT 300'
        );
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Contas em aberto (para os avisos: vencidas e a vencer). @return list<array<string,mixed>> */
    public function contasAbertas(int $idRestaurante): array
    {
        $q = $this->db->prepare("SELECT id, tipo, descricao, categoria, valor, vencimento FROM contas_financeiras WHERE id_restaurante = ? AND status = 'aberta' ORDER BY vencimento ASC, id ASC LIMIT 2000");
        $q->execute([$idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function conta(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM contas_financeiras WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        return $c === false ? null : $c;
    }

    /** @param array{tipo:string,descricao:string,categoria:string,valor:string,vencimento:string,observacao:?string} $d */
    public function criarConta(int $idRestaurante, int $idUser, array $d): int
    {
        $this->db->prepare(
            'INSERT INTO contas_financeiras (id_restaurante, tipo, descricao, categoria, valor, vencimento, observacao, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$idRestaurante, $d['tipo'], $d['descricao'], $d['categoria'], $d['valor'], $d['vencimento'], $d['observacao'], $idUser]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{tipo:string,descricao:string,categoria:string,valor:string,vencimento:string,observacao:?string} $d */
    public function atualizarConta(int $idRestaurante, int $id, array $d): void
    {
        $this->db->prepare(
            'UPDATE contas_financeiras SET tipo = ?, descricao = ?, categoria = ?, valor = ?, vencimento = ?, observacao = ? WHERE id = ? AND id_restaurante = ?'
        )->execute([$d['tipo'], $d['descricao'], $d['categoria'], $d['valor'], $d['vencimento'], $d['observacao'], $id, $idRestaurante]);
    }

    /** Baixa somente se ainda estiver aberta (dois cliques nao baixam duas vezes). */
    public function baixar(int $idRestaurante, int $id, string $dia, ?string $forma): bool
    {
        $q = $this->db->prepare("UPDATE contas_financeiras SET status = 'paga', pago_em = ?, forma = ? WHERE id = ? AND id_restaurante = ? AND status = 'aberta'");
        $q->execute([$dia, $forma, $id, $idRestaurante]);
        return $q->rowCount() === 1;
    }

    public function reabrir(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare("UPDATE contas_financeiras SET status = 'aberta', pago_em = NULL, forma = NULL WHERE id = ? AND id_restaurante = ? AND status = 'paga'");
        $q->execute([$id, $idRestaurante]);
        return $q->rowCount() === 1;
    }

    public function excluirConta(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare('DELETE FROM contas_financeiras WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        return $q->rowCount() === 1;
    }
}
