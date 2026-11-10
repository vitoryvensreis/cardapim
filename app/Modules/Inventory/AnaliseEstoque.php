<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

/**
 * Leitura do estoque para o gerente: o que acabou, o que esta acabando, o que nao pode ser vendido
 * e quanto dinheiro esta parado em estoque. Classe pura (sem banco) para ser facil de testar.
 */
final class AnaliseEstoque
{
    public const FALTA = 'falta';
    public const BAIXO = 'baixo';
    public const OK = 'ok';
    public const INATIVO = 'inativo';

    /** Produto com ate tantas porcoes possiveis entra em "acabando". */
    public const PORCOES_ALERTA = 10;

    /**
     * @param array<string,mixed> $insumo linha de insumos (estoque_atual, estoque_minimo, ativo, unidade)
     * @return array{situacao:string, nivel:int, atual:int, minimo:int, faltaParaMinimo:int}
     *         nivel 0..7 para a barra; quantidades em milesimos
     */
    public static function classificarInsumo(array $insumo): array
    {
        $atual = Quantidade::paraMilesimos((string) $insumo['estoque_atual']);
        $minimo = Quantidade::paraMilesimos((string) $insumo['estoque_minimo']);
        $situacao = match (true) {
            (int) $insumo['ativo'] !== 1 => self::INATIVO,
            $atual <= 0 => self::FALTA,
            $minimo > 0 && $atual <= $minimo => self::BAIXO,
            default => self::OK,
        };
        // Barra: 100% = o dobro do minimo (ou cheio, se nao ha minimo e ainda tem estoque)
        if ($atual <= 0) {
            $nivel = 0;
        } elseif ($minimo <= 0) {
            $nivel = 7;
        } else {
            $nivel = max(1, min(7, (int) floor($atual * 7 / ($minimo * 2))));
        }
        return [
            'situacao' => $situacao,
            'nivel' => $nivel,
            'atual' => $atual,
            'minimo' => $minimo,
            'faltaParaMinimo' => max(0, $minimo - $atual),
        ];
    }

    /**
     * @param list<array<string,mixed>> $insumos linhas de InsumoRepository::listar
     * @param list<array<string,mixed>> $produtos linhas de ProdutoRepository::listar
     * @param array<int,array{disponivel:bool,motivo:?string,porcoes:?int,faltando:list<string>}> $disponibilidade
     * @return array<string,mixed>
     */
    public static function analisar(array $insumos, array $produtos, array $disponibilidade): array
    {
        $emFalta = [];
        $baixos = [];
        $valorDezMilionesimos = 0; // milesimos de quantidade x custo com 4 casas
        $semCusto = 0;
        foreach ($insumos as $insumo) {
            $c = self::classificarInsumo($insumo);
            $item = $insumo + ['analise' => $c];
            if ($c['situacao'] === self::FALTA) {
                $emFalta[] = $item;
            } elseif ($c['situacao'] === self::BAIXO) {
                $baixos[] = $item;
            }
            if ($c['situacao'] !== self::INATIVO) {
                if ($insumo['custo_unitario'] === null || $insumo['custo_unitario'] === '') {
                    $semCusto++;
                } else {
                    $valorDezMilionesimos += $c['atual'] * self::custoInteiro((string) $insumo['custo_unitario']);
                }
            }
        }
        usort($baixos, static fn (array $a, array $b): int => $a['analise']['nivel'] <=> $b['analise']['nivel'] ?: strcmp((string) $a['nome'], (string) $b['nome']));

        $indisponiveis = [];
        $acabando = [];
        $ativos = 0;
        foreach ($produtos as $produto) {
            $d = $disponibilidade[(int) $produto['id']] ?? null;
            if ($d === null || (int) $produto['ativo'] !== 1) {
                continue;
            }
            $ativos++;
            if (!$d['disponivel']) {
                $indisponiveis[] = $produto + ['motivoTexto' => self::motivoTexto($d), 'motivo' => $d['motivo']];
            } elseif ($d['porcoes'] !== null && $d['porcoes'] <= self::PORCOES_ALERTA) {
                $acabando[] = $produto + ['porcoes' => $d['porcoes']];
            }
        }
        usort($acabando, static fn (array $a, array $b): int => $a['porcoes'] <=> $b['porcoes']);

        return [
            'emFalta' => $emFalta,
            'baixos' => $baixos,
            'indisponiveis' => $indisponiveis,
            'acabando' => $acabando,
            'totalInsumos' => count($insumos),
            'totalProdutos' => $ativos,
            // 10^7 por real -> centavos = /10^5, arredondando
            'valorEstoqueCentavos' => intdiv($valorDezMilionesimos + 50000, 100000),
            'insumosSemCusto' => $semCusto,
        ];
    }

    /** @param array{motivo:?string,faltando:list<string>} $d */
    public static function motivoTexto(array $d): string
    {
        return match ($d['motivo']) {
            DisponibilidadeProdutos::MOTIVO_PAUSADO => 'Pausado por você',
            DisponibilidadeProdutos::MOTIVO_ARQUIVADO => 'Arquivado',
            DisponibilidadeProdutos::MOTIVO_INSUMO_INATIVO => 'Insumo desativado: ' . implode(', ', $d['faltando']),
            DisponibilidadeProdutos::MOTIVO_SEM_INSUMO => 'Acabou: ' . implode(', ', $d['faltando']),
            default => 'Disponível',
        };
    }

    private static function custoInteiro(string $decimal): int
    {
        [$inteiro, $fracao] = array_pad(explode('.', $decimal, 2), 2, '');
        return (int) $inteiro * 10000 + (int) str_pad(substr($fracao, 0, 4), 4, '0');
    }
}
