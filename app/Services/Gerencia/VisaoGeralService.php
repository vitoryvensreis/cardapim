<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Inventory\AnaliseEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Repositories\Estoque\ProdutoRepository;
use Cardapim\Repositories\Gerencia\FinanceiroRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\DeliveryService;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;

/**
 * Visao geral do restaurante (primeira tela do gerente): o dia de hoje em numeros, o que esta acontecendo agora
 * (pedidos, mesas, caixa), alertas (estoque, contas) e as vendas recentes. Somente leitura; tudo em centavos.
 */
final class VisaoGeralService
{
    private readonly FinanceiroRepository $fin;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $usuario)
    {
        $this->fin = new FinanceiroRepository($db);
    }

    /** @return array<string,mixed> */
    public function painel(?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable();
        $hoje = $agora->format('Y-m-d');
        $amanha = $agora->modify('+1 day')->format('Y-m-d');
        $ontem = $agora->modify('-1 day');

        $vHoje = $this->fin->vendas($this->idRestaurante, $hoje . ' 00:00:00', $amanha . ' 00:00:00');
        // Comparacao justa: ontem ate a mesma hora de agora.
        $vOntem = $this->fin->vendas($this->idRestaurante, $ontem->format('Y-m-d') . ' 00:00:00', $ontem->format('Y-m-d H:i:s'));
        $totalHoje = Numero::centavos($vHoje['total']);
        $totalOntem = Numero::centavos($vOntem['total']);

        $fila = (new DeliveryService($this->db, $this->idRestaurante, $this->usuario))->fila();
        $porStatus = ['aguardando_pagamento' => 0, 'recebido' => 0, 'em_preparo' => 0, 'pronto' => 0, 'saiu_entrega' => 0];
        foreach ($fila as $p) {
            $porStatus[$p['status']] = ($porStatus[$p['status']] ?? 0) + 1;
        }

        $caixa = (new CaixaRepository($this->db))->aberto($this->idRestaurante);
        $resumoCaixa = $caixa !== null ? (new CaixaService($this->db, $this->idRestaurante, $this->usuario))->resumo($caixa) : null;

        return [
            'agora' => $agora,
            'hoje' => [
                'vendas' => $totalHoje,
                'pedidos' => $vHoje['pedidos'],
                'ticket' => $vHoje['pedidos'] > 0 ? intdiv($totalHoje, $vHoje['pedidos']) : 0,
                'ontem' => $totalOntem,
                'pedidos_ontem' => $vOntem['pedidos'],
                'variacao' => $totalOntem > 0 ? (int) round(($totalHoje - $totalOntem) * 100 / $totalOntem) : null,
            ],
            'fila' => array_slice(array_reverse($fila), 0, 6), // mais novos primeiro
            'filaTotal' => count($fila),
            'porStatus' => $porStatus,
            'mesas' => $this->mesas(),
            'caixa' => $caixa,
            'resumoCaixa' => $resumoCaixa,
            'semana' => $this->semana($agora),
            'horas' => $this->horas($hoje, $amanha, (int) $agora->format('G')),
            'maisVendidos' => $this->maisVendidos($agora->modify('-6 days')->format('Y-m-d') . ' 00:00:00', $amanha . ' 00:00:00'),
            'estoque' => $this->estoque(),
            'contas' => (new FinanceiroService($this->db, $this->idRestaurante))->pendencias($hoje),
        ];
    }

    /** @return array{ativas:int, ocupadas:int, aberto:int} */
    private function mesas(): array
    {
        $q = $this->db->prepare(
            "SELECT COUNT(*) AS ativas,
                    SUM(CASE WHEN EXISTS (SELECT 1 FROM pedidos p WHERE p.id_mesa = m.id AND p.id_restaurante = m.id_restaurante AND p.status = 'aberto') THEN 1 ELSE 0 END) AS ocupadas
             FROM mesas m WHERE m.id_restaurante = ? AND m.ativo = 1 AND m.excluida_em IS NULL"
        );
        $q->execute([$this->idRestaurante]);
        $m = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $v = $this->db->prepare("SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE id_restaurante = ? AND status = 'aberto' AND id_mesa IS NOT NULL");
        $v->execute([$this->idRestaurante]);
        return ['ativas' => (int) ($m['ativas'] ?? 0), 'ocupadas' => (int) ($m['ocupadas'] ?? 0), 'aberto' => Numero::centavos((string) $v->fetchColumn())];
    }

    /** Vendas dos ultimos 7 dias (com os dias sem venda). @return list<array{dia:string,pedidos:int,total:int}> */
    private function semana(DateTimeImmutable $agora): array
    {
        $inicio = $agora->modify('-6 days')->format('Y-m-d');
        $porDia = [];
        foreach ($this->fin->vendasPorDia($this->idRestaurante, $inicio . ' 00:00:00', $agora->modify('+1 day')->format('Y-m-d') . ' 00:00:00') as $d) {
            $porDia[$d['dia']] = $d;
        }
        $dias = [];
        for ($i = 6; $i >= 0; $i--) {
            $dia = $agora->modify('-' . $i . ' days')->format('Y-m-d');
            $dias[] = ['dia' => $dia, 'pedidos' => (int) ($porDia[$dia]['pedidos'] ?? 0), 'total' => Numero::centavos((string) ($porDia[$dia]['total'] ?? '0'))];
        }
        return $dias;
    }

    /**
     * Vendas de hoje por hora (das 6h ate a hora atual, ou a faixa com venda se for maior).
     * @return list<array{hora:int,pedidos:int,total:int}>
     */
    private function horas(string $hoje, string $amanha, int $horaAgora): array
    {
        $q = $this->db->prepare(
            "SELECT SUBSTR(concluido_em, 12, 2) AS hora, COUNT(*) AS pedidos, SUM(total) AS total FROM pedidos
             WHERE id_restaurante = ? AND status = 'concluido' AND concluido_em >= ? AND concluido_em < ?
             GROUP BY SUBSTR(concluido_em, 12, 2)"
        );
        $q->execute([$this->idRestaurante, $hoje . ' 00:00:00', $amanha . ' 00:00:00']);
        $porHora = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porHora[(int) $r['hora']] = ['pedidos' => (int) $r['pedidos'], 'total' => Numero::centavos((string) $r['total'])];
        }
        if ($porHora === []) {
            return [];
        }
        $de = min(6, ...array_keys($porHora));
        $ate = max($horaAgora, ...array_keys($porHora));
        $horas = [];
        for ($h = $de; $h <= $ate; $h++) {
            $horas[] = ['hora' => $h, 'pedidos' => $porHora[$h]['pedidos'] ?? 0, 'total' => $porHora[$h]['total'] ?? 0];
        }
        return $horas;
    }

    /** Produtos mais vendidos (pedidos concluidos, itens nao cancelados). @return list<array{nome:string,quantidade:int,total:int}> */
    private function maisVendidos(string $de, string $ate): array
    {
        $q = $this->db->prepare(
            "SELECT i.nome, SUM(i.quantidade) AS quantidade, SUM(i.total) AS total
             FROM pedido_itens i JOIN pedidos p ON p.id = i.id_pedido AND p.id_restaurante = i.id_restaurante
             WHERE i.id_restaurante = ? AND i.cancelado_em IS NULL AND p.status = 'concluido' AND p.concluido_em >= ? AND p.concluido_em < ?
             GROUP BY i.nome ORDER BY SUM(i.quantidade) DESC, SUM(i.total) DESC LIMIT 5"
        );
        $q->execute([$this->idRestaurante, $de, $ate]);
        return array_map(static fn (array $r): array => ['nome' => (string) $r['nome'], 'quantidade' => (int) $r['quantidade'], 'total' => Numero::centavos((string) $r['total'])], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{emFalta:list<array<string,mixed>>, baixos:list<array<string,mixed>>, indisponiveis:int, totalInsumos:int, totalProdutos:int} */
    private function estoque(): array
    {
        $insumos = (new InsumoRepository($this->db))->listar($this->idRestaurante);
        $produtos = (new ProdutoRepository($this->db))->listar($this->idRestaurante);
        $a = AnaliseEstoque::analisar($insumos, $produtos, (new DisponibilidadeProdutos($this->db))->verificar($this->idRestaurante));
        return [
            'emFalta' => $a['emFalta'],
            'baixos' => $a['baixos'],
            'indisponiveis' => count($a['indisponiveis']),
            'totalInsumos' => (int) $a['totalInsumos'],
            'totalProdutos' => (int) $a['totalProdutos'],
        ];
    }
}
