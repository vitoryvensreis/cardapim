<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Modules\Inventory\QuantidadeVenda;
use Cardapim\Shared\Numero;

/**
 * Agrupamento somente para apresentacao: nunca altera, cancela ou consolida registros de pedido_itens.
 * Preserva os IDs, autores e horarios de cada lancamento; combos mantem o seu lote original.
 */
final class AgrupadorItensComanda
{
    /**
     * @param list<array<string,mixed>> $itens Ordenados pelo ID original
     * @return list<array<string,mixed>>
     */
    public static function agrupar(array $itens): array
    {
        $grupos = [];
        $posicoes = [];
        foreach ($itens as $item) {
            $unidade = QuantidadeVenda::unidade((string) ($item['unidade'] ?? 'un'));
            $cancelado = ($item['cancelado_em'] ?? null) !== null;
            $lote = $item['combo_lote'] ?? null;
            $combo = $lote !== null && (string) $lote !== '';

            if ($combo) {
                // Nao mistura dois lotes de combo, mesmo que tenham o mesmo nome.
                $chave = 'combo:' . $lote . ':' . ($cancelado ? 'cancelado' : 'ativo');
            } elseif ($cancelado || QuantidadeVenda::porPeso($unidade) || (int) ($item['id_produto'] ?? 0) <= 0) {
                // Cancelados, itens sem produto e vendas por peso/volume permanecem individuais.
                $chave = 'item:' . (int) $item['id'];
            } else {
                // Equivalencia estrita: preserva precos historicos, observacoes e complementos.
                $chave = 'produto:' . hash('sha256', json_encode([
                    (int) $item['id_produto'],
                    (string) ($item['nome'] ?? ''),
                    (string) ($item['preco_unitario'] ?? ''),
                    $unidade,
                    (string) ($item['complementos_texto'] ?? ''),
                    (string) ($item['observacao'] ?? ''),
                ], JSON_THROW_ON_ERROR));
            }

            if (!array_key_exists($chave, $posicoes)) {
                $posicoes[$chave] = count($grupos);
                $grupos[] = [
                    'tipo' => $combo ? 'combo' : 'produto',
                    'id' => (int) $item['id'],
                    'nome' => $combo ? (string) ($item['combo_nome'] ?? '') : (string) ($item['nome'] ?? ''),
                    'unidade' => $unidade,
                    'cancelado' => $cancelado,
                    'quantidade_milesimos' => 0,
                    'quantidade_texto' => '',
                    'total_centavos' => 0,
                    'lancamentos' => [],
                ];
            }
            $indice = $posicoes[$chave];
            $grupos[$indice]['quantidade_milesimos'] += QuantidadeVenda::doBanco($item['quantidade'] ?? '0');
            $grupos[$indice]['total_centavos'] += Numero::centavos((string) ($item['total'] ?? '0'));
            $grupos[$indice]['lancamentos'][] = $item;
        }

        foreach ($grupos as &$grupo) {
            if ($grupo['tipo'] === 'produto') {
                $grupo['quantidade_texto'] = QuantidadeVenda::texto($grupo['quantidade_milesimos'], $grupo['unidade']);
            }
        }
        unset($grupo);

        return $grupos;
    }
}
