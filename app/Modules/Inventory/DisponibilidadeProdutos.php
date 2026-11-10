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
     * @return array<int, array{disponivel:bool, motivo:?string, porcoes:?int, faltando:list<string>}>
     *         porcoes = quantas unidades ainda podem ser vendidas (null = sem controle de estoque)
     */
    public function verificar(int $idRestaurante, array $idsProdutos = []): array
    {
        $sql = 'SELECT id, ativo, disponivel, controla_estoque FROM produtos WHERE id_restaurante = ?';
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
            $porcoes = null;
            $faltando = [];
            $motivo = null;
            if ((int) $p['controla_estoque'] === 1) {
                foreach ($fichas[$id] ?? [] as $item) {
                    if ((int) $item['insumo_ativo'] !== 1) {
                        $motivo ??= self::MOTIVO_INSUMO_INATIVO;
                        $faltando[] = (string) $item['nome'];
                        $porcoes = 0;
                        continue;
                    }
                    $cabe = Quantidade::quantasVezes(
                        Quantidade::paraMilesimos((string) $item['estoque_atual']),
                        Quantidade::paraMilesimos((string) $item['quantidade']),
                    );
                    if ($cabe === 0) {
                        $motivo ??= self::MOTIVO_SEM_INSUMO;
                        $faltando[] = (string) $item['nome'];
                    }
                    $porcoes = $porcoes === null ? $cabe : min($porcoes, $cabe);
                }
            }
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
