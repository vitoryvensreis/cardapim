<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use PDO;

/**
 * Numeros do Dashboard da plataforma (/painel). So leitura; datas sempre calculadas no PHP (o mesmo SQL roda no
 * MySQL e no SQLite dos testes locais).
 */
final class DashboardRepository
{
    /** Pedidos que contam como feitos (sem os cancelados e os Pix nao pagos). */
    private const PEDIDO_VALIDO = "status NOT IN ('cancelado', 'aguardando_pagamento')";

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{pendente:int, ativo:int, inadimplente:int, suspenso:int, inativo:int, total:int, novos:int} */
    public function restaurantes(string $desde): array
    {
        $n = ['pendente' => 0, 'ativo' => 0, 'inadimplente' => 0, 'suspenso' => 0, 'inativo' => 0];
        foreach ($this->db->query('SELECT status_rest, COUNT(*) FROM restaurantes GROUP BY status_rest')->fetchAll(PDO::FETCH_NUM) as [$st, $qt]) {
            if (isset($n[$st])) {
                $n[$st] = (int) $qt;
            }
        }
        $q = $this->db->prepare('SELECT COUNT(*) FROM restaurantes WHERE created_at >= ?');
        $q->execute([$desde]);
        return [...$n, 'total' => array_sum($n), 'novos' => (int) $q->fetchColumn()];
    }

    /** @return array{pedidos:int, valor:string, restaurantes:int} */
    public function pedidos(string $inicio, string $fim): array
    {
        $q = $this->db->prepare('SELECT COUNT(*), COALESCE(SUM(total), 0), COUNT(DISTINCT id_restaurante) FROM pedidos
            WHERE criado_em >= ? AND criado_em < ? AND ' . self::PEDIDO_VALIDO);
        $q->execute([$inicio, $fim]);
        $r = $q->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        return ['pedidos' => (int) $r[0], 'valor' => (string) $r[1], 'restaurantes' => (int) $r[2]];
    }

    /** @return array<string, array{pedidos:int, valor:string}> por dia (AAAA-MM-DD) */
    public function pedidosPorDia(string $inicio, string $fim): array
    {
        $q = $this->db->prepare('SELECT SUBSTR(criado_em, 1, 10) AS dia, COUNT(*), COALESCE(SUM(total), 0) FROM pedidos
            WHERE criado_em >= ? AND criado_em < ? AND ' . self::PEDIDO_VALIDO . ' GROUP BY SUBSTR(criado_em, 1, 10)');
        $q->execute([$inicio, $fim]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$dia, $qt, $valor]) {
            $out[(string) $dia] = ['pedidos' => (int) $qt, 'valor' => (string) $valor];
        }
        return $out;
    }

    /** @return array<string,int> pedidos por origem (cardapio, balcao, mesa, ifood, whatsapp) */
    public function porOrigem(string $inicio, string $fim): array
    {
        $q = $this->db->prepare('SELECT origem, COUNT(*) FROM pedidos WHERE criado_em >= ? AND criado_em < ? AND ' . self::PEDIDO_VALIDO . ' GROUP BY origem');
        $q->execute([$inicio, $fim]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$origem, $qt]) {
            $out[(string) $origem] = (int) $qt;
        }
        arsort($out);
        return $out;
    }

    /** @return list<array{id:int, nome:string, pedidos:int, valor:string}> restaurantes com mais pedidos no periodo */
    public function maisPedidos(string $inicio, string $fim, int $limite = 5): array
    {
        $q = $this->db->prepare('SELECT r.id, r.nome, COUNT(*) AS pedidos, COALESCE(SUM(p.total), 0) AS valor
            FROM pedidos p JOIN restaurantes r ON r.id = p.id_restaurante
            WHERE p.criado_em >= ? AND p.criado_em < ? AND p.' . self::PEDIDO_VALIDO . '
            GROUP BY r.id, r.nome ORDER BY pedidos DESC, valor DESC LIMIT ' . max(1, $limite));
        $q->execute([$inicio, $fim]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'pedidos' => (int) $r['pedidos'], 'valor' => (string) $r['valor']],
            $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Assinaturas vigentes: quantas pagas, quantas gratis e a receita recorrente por mes (valor dividido pelos meses do periodo).
     * @return array{pagas:int, gratis:int, mensal:string}
     */
    public function assinaturas(): array
    {
        $r = $this->db->query("SELECT
                COALESCE(SUM(CASE WHEN valor > 0 THEN 1 ELSE 0 END), 0),
                COALESCE(SUM(CASE WHEN valor = 0 THEN 1 ELSE 0 END), 0),
                COALESCE(SUM(CASE periodo WHEN 'mensal' THEN valor WHEN 'semestral' THEN valor / 6 WHEN 'anual' THEN valor / 12 ELSE 0 END), 0)
            FROM assinaturas WHERE status = 'ativa'")->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        return ['pagas' => (int) $r[0], 'gratis' => (int) $r[1], 'mensal' => number_format((float) $r[2], 2, '.', '')];
    }

    /**
     * Situacao da rotina do iFood: lojas conectadas e quando a rotina passou pela ultima vez.
     * @return array{lojas:int, ultima:?string}
     */
    public function ifood(): array
    {
        $r = $this->db->query("SELECT COUNT(*), MAX(verificado_em) FROM integracoes WHERE provedor = 'ifood' AND status = 'conectado' AND id_restaurante IS NOT NULL")
            ->fetch(PDO::FETCH_NUM) ?: [0, null];
        return ['lojas' => (int) $r[0], 'ultima' => $r[1] !== null ? (string) $r[1] : null];
    }
}
