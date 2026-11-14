<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

use PDO;

/**
 * Regra de venda: produto indisponivel se estiver arquivado, pausado manualmente
 * ou se faltar qualquer insumo da ficha tecnica (ou o insumo estiver inativo).
 */
final class DisponibilidadeProdutos
{
    public const MOTIVO_ARQUIVADO = 'arquivado';
    public const MOTIVO_PAUSADO = 'pausado';
    public const MOTIVO_SEM_INSUMO = 'sem_insumo';
    public const MOTIVO_INSUMO_INATIVO = 'insumo_inativo';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<int> $idsProdutos vazio = todos os produtos do restaurante
     * @return array<int, array{disponivel:bool, motivo:?string, porcoes:?int, restante:?int, unidade:string, faltando:list<string>}>
     *         porcoes = quantas unidades inteiras (ou quilos/litros inteiros) ainda podem ser vendidas;
     *         restante = quanto ainda pode ser vendido em milesimos (0,846 kg = 846); null = sem controle de estoque.
     *         Produto por peso fica indisponivel quando o que resta e menor que a venda minima (ou 1 g/1 ml).
     */
    public function verificar(int $idRestaurante, array $idsProdutos = []): array
    {
        $sql = 'SELECT id, ativo, disponivel, controla_estoque, unidade_venda, venda_minima FROM produtos WHERE id_restaurante = ?';
        $params = [$idRestaurante];
        if ($idsProdutos !== []) {
            $ids = array_values(array_unique(array_map('intval', $idsProdutos)));
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $query = $this->db->prepare($sql);
        $query->execute($params);
        $produtos = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($produtos === []) {
            return [];
        }

        $controlados = array_map(static fn (array $p): int => (int) $p['id'], array_filter(
            $produtos,
            static fn (array $p): bool => (int) $p['controla_estoque'] === 1,
        ));
        $fichas = $this->fichas($idRestaurante, $controlados);

        $resultado = [];
        foreach ($produtos as $p) {
            $id = (int) $p['id'];
            $unidade = QuantidadeVenda::unidade($p['unidade_venda'] ?? 'un');
            $minimo = $unidade === 'un' ? 1000 : max(1, QuantidadeVenda::doBanco($p['venda_minima'] ?? null));
            $restante = null;
            $faltando = [];
            $motivo = null;
            if ((int) $p['controla_estoque'] === 1) {
                foreach ($fichas[$id] ?? [] as $item) {
                    if ((int) $item['insumo_ativo'] !== 1) {
                        $motivo ??= self::MOTIVO_INSUMO_INATIVO;
                        $faltando[] = (string) $item['nome'];
                        $restante = 0;
                        continue;
                    }
                    // Quanto do produto (em milesimos da unidade de venda) este insumo ainda permite vender.
                    $estoque = max(0, Quantidade::paraMilesimos((string) $item['estoque_atual']));
                    $porUnidade = Quantidade::paraMilesimos((string) $item['quantidade']);
                    $cabe = $porUnidade > 0 ? intdiv($estoque * 1000, $porUnidade) : PHP_INT_MAX;
                    if ($unidade === 'un') {
                        $cabe -= $cabe % 1000; // por unidade: so unidades inteiras
                    }
                    if ($cabe < $minimo) {
                        $motivo ??= self::MOTIVO_SEM_INSUMO;
                        $faltando[] = (string) $item['nome'];
                    }
                    $restante = $restante === null ? $cabe : min($restante, $cabe);
                }
            }
            $porcoes = $restante === null ? null : intdiv($restante, 1000);
            if ((int) $p['disponivel'] !== 1) {
                $motivo = self::MOTIVO_PAUSADO;
            }
            if ((int) $p['ativo'] !== 1) {
                $motivo = self::MOTIVO_ARQUIVADO;
            }
            $resultado[$id] = [
                'disponivel' => $motivo === null,
                'motivo' => $motivo,
                'porcoes' => $porcoes,
                'restante' => $restante,
                'unidade' => $unidade,
                'faltando' => $faltando,
            ];
        }
        return $resultado;
    }

    /** @return array<int, list<array<string,mixed>>> ficha tecnica por produto */
    private function fichas(int $idRestaurante, array $idsProdutos): array
    {
        if ($idsProdutos === []) {
            return [];
        }
        $query = $this->db->prepare(
            'SELECT pi.id_produto, pi.quantidade, i.nome, i.estoque_atual, i.ativo AS insumo_ativo
             FROM produto_insumos pi
             JOIN insumos i ON i.id = pi.id_insumo AND i.id_restaurante = pi.id_restaurante
             WHERE pi.id_restaurante = ? AND pi.id_produto IN (' . implode(',', array_fill(0, count($idsProdutos), '?')) . ')
             ORDER BY pi.id_produto, i.nome'
        );
        $query->execute([$idRestaurante, ...$idsProdutos]);
        $fichas = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fichas[(int) $row['id_produto']][] = $row;
        }
        return $fichas;
    }
}
