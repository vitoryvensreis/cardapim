<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\FinanceiroRepository;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Shared\Numero;

/**
 * Relatorio do Financeiro (regime de caixa: o que de fato entrou e saiu no periodo).
 *
 * Entrou = pagamentos de pedidos + "outra entrada" na gaveta + contas a receber recebidas.
 * Saiu   = saidas da gaveta (menos sangria) + contas a pagar pagas.
 * Sangria e reforco de troco so mudam o dinheiro de lugar (gaveta <-> cofre): aparecem como informacao, sem entrar no resultado.
 * Tudo em centavos (inteiros), nunca float.
 */
final class FinanceiroService
{
    /** Movimentos de gaveta que so transferem dinheiro (nao sao receita nem despesa). */
    public const TRANSFERENCIAS = ['sangria', 'reforco'];

    public const ORIGENS = [
        'balcao' => 'Balcão',
        'mesa' => 'Mesas',
        'cardapio' => 'Cardápio online',
        'whatsapp' => 'WhatsApp',
        'ifood' => 'iFood',
    ];

    private readonly FinanceiroRepository $repo;

    public function __construct(\PDO $db, private readonly int $idRestaurante)
    {
        $this->repo = new FinanceiroRepository($db);
    }

    /**
     * @return array{
     *   entrou:int, saiu:int, resultado:int, vendas:array{pedidos:int,total:int,ticket:int,taxa_entrega:int,desconto:int},
     *   formas:array<string,int>, contas_recebidas:int, outras_entradas:int, saidas:array<string,int>, contas_pagas:int,
     *   sangria:int, reforco:int, origens:list<array{origem:string,rotulo:string,pedidos:int,total:int}>,
     *   dias:list<array{dia:string,pedidos:int,vendas:int,entrou:int,saiu:int,resultado:int}>
     * }
     */
    public function resumo(PeriodoFinanceiro $p): array
    {
        $de = $p->inicio();
        $ate = $p->fimExclusivo();
        $dias = [];
        $dia = function (string $d) use (&$dias): void {
            $dias[$d] ??= ['dia' => $d, 'pedidos' => 0, 'vendas' => 0, 'entrou' => 0, 'saiu' => 0, 'resultado' => 0];
        };

        $formas = array_fill_keys(array_keys(CaixaService::FORMAS), 0);
        foreach ($this->repo->recebidoPorFormaEDia($this->idRestaurante, $de, $ate) as $r) {
            $c = Numero::centavos($r['total']);
            $formas[$r['forma']] = ($formas[$r['forma']] ?? 0) + $c;
            $dia($r['dia']);
            $dias[$r['dia']]['entrou'] += $c;
        }

        foreach ($this->repo->vendasPorDia($this->idRestaurante, $de, $ate) as $r) {
            $dia($r['dia']);
            $dias[$r['dia']]['pedidos'] = $r['pedidos'];
            $dias[$r['dia']]['vendas'] = Numero::centavos($r['total']);
        }

        $saidas = [];
        $outrasEntradas = $sangria = $reforco = 0;
        foreach ($this->repo->movimentosCaixa($this->idRestaurante, $de, $ate) as $m) {
            $c = Numero::centavos((string) $m['valor']);
            $d = substr((string) $m['criado_em'], 0, 10);
            if ($m['categoria'] === 'sangria') {
                $sangria += $c;
                continue;
            }
            if ($m['categoria'] === 'reforco') {
                $reforco += $c;
                continue;
            }
            $dia($d);
            if ($m['tipo'] === 'entrada') {
                $outrasEntradas += $c;
                $dias[$d]['entrou'] += $c;
            } else {
                $saidas['caixa:' . $m['categoria']] = ($saidas['caixa:' . $m['categoria']] ?? 0) + $c;
                $dias[$d]['saiu'] += $c;
            }
        }

        $contasRecebidas = $contasPagas = 0;
        foreach ($this->repo->contasBaixadas($this->idRestaurante, $p->de, $p->ate) as $conta) {
            $c = Numero::centavos((string) $conta['valor']);
            $d = (string) $conta['pago_em'];
            $dia($d);
            if ($conta['tipo'] === 'receber') {
                $contasRecebidas += $c;
                $dias[$d]['entrou'] += $c;
            } else {
                $contasPagas += $c;
                $saidas['conta:' . $conta['categoria']] = ($saidas['conta:' . $conta['categoria']] ?? 0) + $c;
                $dias[$d]['saiu'] += $c;
            }
        }
        arsort($saidas);

        $v = $this->repo->vendas($this->idRestaurante, $de, $ate);
        $totalVendas = Numero::centavos($v['total']);
        $origens = [];
        foreach ($this->repo->vendasPorOrigem($this->idRestaurante, $de, $ate) as $o) {
            $origens[] = ['origem' => $o['origem'], 'rotulo' => self::ORIGENS[$o['origem']] ?? 'Outros', 'pedidos' => $o['pedidos'], 'total' => Numero::centavos($o['total'])];
        }
        usort($origens, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        foreach ($dias as &$linha) {
            $linha['resultado'] = $linha['entrou'] - $linha['saiu'];
        }
        unset($linha);
        krsort($dias);

        $entrou = array_sum($formas) + $outrasEntradas + $contasRecebidas;
        $saiu = array_sum($saidas);
        return [
            'entrou' => $entrou,
            'saiu' => $saiu,
            'resultado' => $entrou - $saiu,
            'vendas' => [
                'pedidos' => $v['pedidos'],
                'total' => $totalVendas,
                'ticket' => $v['pedidos'] > 0 ? intdiv($totalVendas, $v['pedidos']) : 0,
                'taxa_entrega' => Numero::centavos($v['taxa_entrega']),
                'desconto' => Numero::centavos($v['desconto']),
            ],
            'formas' => $formas,
            'contas_recebidas' => $contasRecebidas,
            'outras_entradas' => $outrasEntradas,
            'saidas' => $saidas,
            'contas_pagas' => $contasPagas,
            'sangria' => $sangria,
            'reforco' => $reforco,
            'origens' => $origens,
            'dias' => array_values($dias),
        ];
    }

    /** Nome legivel de uma chave de saida ("caixa:fornecedor" ou "conta:aluguel"). */
    public static function rotuloSaida(string $chave): string
    {
        [$fonte, $categoria] = explode(':', $chave, 2) + [1 => ''];
        if ($fonte === 'caixa') {
            return (CaixaService::SAIDAS[$categoria] ?? 'Outra saída') . ' (caixa)';
        }
        return ContaFinanceiraService::rotuloCategoria('pagar', $categoria);
    }

    /**
     * Extrato do periodo: vendas recebidas (uma linha por dia e forma), movimentos da gaveta e contas baixadas.
     * @return list<array{quando:string,dia:string,sentido:string,tipo:string,categoria:string,descricao:string,valor:int,autor:string,transferencia:bool,link:?string}>
     */
    public function extrato(PeriodoFinanceiro $p): array
    {
        $de = $p->inicio();
        $ate = $p->fimExclusivo();
        $linhas = [];
        foreach ($this->repo->recebidoPorFormaEDia($this->idRestaurante, $de, $ate) as $r) {
            $linhas[] = [
                'quando' => $r['dia'] . ' 23:59:59', 'dia' => $r['dia'], 'sentido' => 'entrada', 'tipo' => 'venda',
                'categoria' => 'Vendas', 'descricao' => 'Vendas recebidas em ' . mb_strtolower(CaixaService::FORMAS[$r['forma']] ?? $r['forma']),
                'valor' => Numero::centavos($r['total']), 'autor' => '', 'transferencia' => false, 'link' => null,
            ];
        }
        foreach ($this->repo->movimentosCaixa($this->idRestaurante, $de, $ate) as $m) {
            $entrada = $m['tipo'] === 'entrada';
            $categoria = $entrada ? (CaixaService::ENTRADAS[$m['categoria']] ?? 'Outra entrada') : (CaixaService::SAIDAS[$m['categoria']] ?? 'Outra saída');
            $linhas[] = [
                'quando' => (string) $m['criado_em'], 'dia' => substr((string) $m['criado_em'], 0, 10), 'sentido' => $entrada ? 'entrada' : 'saida', 'tipo' => 'caixa',
                'categoria' => $categoria, 'descricao' => trim((string) ($m['descricao'] ?? '')) !== '' ? (string) $m['descricao'] : $categoria,
                'valor' => Numero::centavos((string) $m['valor']), 'autor' => trim((string) ($m['autor'] ?? '')),
                'transferencia' => in_array($m['categoria'], self::TRANSFERENCIAS, true), 'link' => null,
            ];
        }
        foreach ($this->repo->contasBaixadas($this->idRestaurante, $p->de, $p->ate) as $c) {
            $receber = $c['tipo'] === 'receber';
            $linhas[] = [
                'quando' => $c['pago_em'] . ' 12:00:00', 'dia' => (string) $c['pago_em'], 'sentido' => $receber ? 'entrada' : 'saida', 'tipo' => 'conta',
                'categoria' => ContaFinanceiraService::rotuloCategoria((string) $c['tipo'], (string) $c['categoria']),
                'descricao' => (string) $c['descricao'] . ($c['forma'] ? ' · ' . (ContaFinanceiraService::FORMAS[$c['forma']] ?? '') : ''),
                'valor' => Numero::centavos((string) $c['valor']), 'autor' => trim((string) ($c['autor'] ?? '')), 'transferencia' => false,
                'link' => 'financeiro/contas/' . (int) $c['id'],
            ];
        }
        usort($linhas, static fn (array $a, array $b): int => strcmp($b['quando'], $a['quando']));
        return $linhas;
    }

    /**
     * Fechamentos de caixa do periodo e o total de sobras/faltas.
     * @return array{caixas:list<array<string,mixed>>, fechados:int, sobras:int, faltas:int, vendas:int}
     */
    public function fechamentos(PeriodoFinanceiro $p): array
    {
        $caixas = $this->repo->caixas($this->idRestaurante, $p->inicio(), $p->fimExclusivo());
        $fechados = $sobras = $faltas = $vendas = 0;
        foreach ($caixas as $c) {
            if ($c['status'] !== 'fechado') {
                continue;
            }
            $fechados++;
            $dif = $c['diferenca'] !== null ? Numero::centavos((string) $c['diferenca']) : 0;
            $dif > 0 ? $sobras += $dif : $faltas += -$dif;
            $resumo = json_decode((string) ($c['resumo'] ?? ''), true);
            $vendas += is_array($resumo) ? (int) ($resumo['vendas'] ?? 0) : 0;
        }
        return ['caixas' => $caixas, 'fechados' => $fechados, 'sobras' => $sobras, 'faltas' => $faltas, 'vendas' => $vendas];
    }

    /**
     * Avisos de contas em aberto: vencidas e que vencem nos proximos 7 dias.
     * @return array{vencidas:int, vencidas_valor:int, semana:int, semana_valor:int, receber:int, receber_valor:int, pagar:int, pagar_valor:int}
     */
    public function pendencias(string $hoje): array
    {
        $limite = date('Y-m-d', (int) strtotime($hoje . ' +7 days'));
        $r = ['vencidas' => 0, 'vencidas_valor' => 0, 'semana' => 0, 'semana_valor' => 0, 'receber' => 0, 'receber_valor' => 0, 'pagar' => 0, 'pagar_valor' => 0];
        foreach ($this->repo->contasAbertas($this->idRestaurante) as $c) {
            $v = Numero::centavos((string) $c['valor']);
            $chave = $c['tipo'] === 'receber' ? 'receber' : 'pagar';
            $r[$chave]++;
            $r[$chave . '_valor'] += $v;
            if ($c['tipo'] !== 'pagar') {
                continue;
            }
            if ($c['vencimento'] < $hoje) {
                $r['vencidas']++;
                $r['vencidas_valor'] += $v;
            } elseif ($c['vencimento'] <= $limite) {
                $r['semana']++;
                $r['semana_valor'] += $v;
            }
        }
        return $r;
    }

    /** Planilha (CSV com ; e BOM, abre direto no Excel em portugues) com o resumo e o extrato do periodo. */
    public function csv(PeriodoFinanceiro $p, string $restaurante): string
    {
        $r = $this->resumo($p);
        $reais = static fn (int $c): string => str_replace('.', ',', CaixaService::dec($c));
        $linhas = [
            ['Relatório financeiro', $restaurante],
            ['Período', $p->rotulo()],
            [],
            ['Resumo', 'Valor (R$)'],
            ['Entrou', $reais($r['entrou'])],
            ['Saiu', $reais($r['saiu'])],
            ['Resultado', $reais($r['resultado'])],
            ['Pedidos concluídos', (string) $r['vendas']['pedidos']],
            ['Total vendido', $reais($r['vendas']['total'])],
            ['Ticket médio', $reais($r['vendas']['ticket'])],
            ['Sangrias (gaveta para o cofre)', $reais($r['sangria'])],
            [],
            ['Data', 'Hora', 'Entrada ou saída', 'Categoria', 'Descrição', 'Valor (R$)', 'Responsável'],
        ];
        foreach ($this->extrato($p) as $l) {
            $hora = in_array($l['tipo'], ['venda', 'conta'], true) ? '' : substr($l['quando'], 11, 5);
            $sentido = $l['transferencia'] ? 'Transferência' : ($l['sentido'] === 'entrada' ? 'Entrada' : 'Saída');
            $linhas[] = [date('d/m/Y', (int) strtotime($l['dia'])), $hora, $sentido, $l['categoria'], $l['descricao'], ($l['sentido'] === 'saida' ? '-' : '') . $reais($l['valor']), $l['autor']];
        }
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(self::seguroParaPlanilha(...), $linha), ';', '"', '');
        }
        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);
        return $csv;
    }

    /** Evita que um texto digitado vire formula ao abrir a planilha (=, +, -, @ no inicio). */
    private static function seguroParaPlanilha(string $valor): string
    {
        if ($valor !== '' && preg_match('/\A-?\d[\d.,]*\z/', $valor) !== 1 && in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $valor;
        }
        return $valor;
    }
}
