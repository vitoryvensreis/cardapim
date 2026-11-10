<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Baixa de insumos pela ficha tecnica quando um pedido e feito.
 *
 * - Tudo ou nada: se faltar qualquer insumo, nada e baixado (EstoqueInsuficiente).
 * - Estoque nunca fica negativo (regra do produto + CHECK no banco).
 * - Linhas de insumo travadas (SELECT ... FOR UPDATE, ordem por id) para vendas simultaneas.
 * - Retorna o consumo exato: o modulo de pedidos deve guardar esse retorno no item do pedido
 *   para devolver o estoque no cancelamento e para nao baixar o mesmo pedido duas vezes.
 */
final class BaixaEstoque
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<int,int> $itens id_produto => quantidade vendida (inteiro > 0)
     * @return array<int,string> consumo por insumo: id_insumo => quantidade baixada ("0.240")
     * @throws EstoqueInsuficiente|InvalidArgumentException
     */
    public function baixar(int $idRestaurante, array $itens): array
    {
        $itens = $this->validarItens($itens);
        return $this->emTransacao(function () use ($idRestaurante, $itens): array {
            $necessidade = $this->necessidade($idRestaurante, $itens);
            if ($necessidade === []) {
                return [];
            }
            $insumos = $this->travarInsumos($idRestaurante, array_keys($necessidade));

            $faltas = [];
            foreach ($necessidade as $idInsumo => $milesimos) {
                $insumo = $insumos[$idInsumo] ?? null;
                $saldo = $insumo !== null ? Quantidade::paraMilesimos((string) $insumo['estoque_atual']) : 0;
                if ($insumo === null || (int) $insumo['ativo'] !== 1 || $saldo < $milesimos) {
                    $unidade = UnidadeMedida::tryFrom((string) ($insumo['unidade'] ?? 'un')) ?? UnidadeMedida::Unidade;
                    $faltas[] = [
                        'id_insumo' => $idInsumo,
                        'nome' => (string) ($insumo['nome'] ?? 'Insumo'),
                        'necessario' => $unidade->formatar($milesimos),
                        'disponivel' => $unidade->formatar(max($saldo, 0)),
                        'unidade' => $unidade->value,
                    ];
                }
            }
            if ($faltas !== []) {
                throw new EstoqueInsuficiente($faltas);
            }

            $update = $this->db->prepare(
                'UPDATE insumos SET estoque_atual = estoque_atual - ?
                 WHERE id = ? AND id_restaurante = ? AND estoque_atual >= ?'
            );
            $consumo = [];
            foreach ($necessidade as $idInsumo => $milesimos) {
                $decimal = Quantidade::paraDecimal($milesimos);
                $update->execute([$decimal, $idInsumo, $idRestaurante, $decimal]);
                if ($update->rowCount() !== 1) {
                    // Nao deveria ocorrer com a linha travada; garante o tudo ou nada.
                    throw new EstoqueInsuficiente([[
                        'id_insumo' => $idInsumo, 'nome' => (string) $insumos[$idInsumo]['nome'],
                        'necessario' => $decimal, 'disponivel' => (string) $insumos[$idInsumo]['estoque_atual'],
                        'unidade' => (string) $insumos[$idInsumo]['unidade'],
                    ]]);
                }
                $consumo[$idInsumo] = $decimal;
            }
            return $consumo;
        });
    }

    /**
     * Devolve ao estoque o consumo retornado por baixar() (ex.: pedido cancelado).
     * @param array<int,string> $consumo id_insumo => quantidade
     */
    public function devolver(int $idRestaurante, array $consumo): void
    {
        $this->emTransacao(function () use ($idRestaurante, $consumo): void {
            $update = $this->db->prepare(
                'UPDATE insumos SET estoque_atual = estoque_atual + ? WHERE id = ? AND id_restaurante = ?'
            );
            ksort($consumo);
            foreach ($consumo as $idInsumo => $quantidade) {
                $milesimos = Quantidade::paraMilesimos((string) $quantidade);
                if ($milesimos <= 0) {
                    throw new InvalidArgumentException('Quantidade de devolucao invalida.');
                }
                $update->execute([Quantidade::paraDecimal($milesimos), (int) $idInsumo, $idRestaurante]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidArgumentException('Insumo nao pertence a este restaurante.');
                }
            }
        });
    }

    /** @return array<int,int> */
    private function validarItens(array $itens): array
    {
        $validos = [];
        foreach ($itens as $idProduto => $quantidade) {
            if (!is_int($idProduto) || $idProduto < 1 || !is_int($quantidade) || $quantidade < 1 || $quantidade > 10000) {
                throw new InvalidArgumentException('Itens do pedido invalidos.');
            }
            $validos[$idProduto] = $quantidade;
        }
        if ($validos === []) {
            throw new InvalidArgumentException('Pedido sem itens.');
        }
        return $validos;
    }

    /**
     * Soma o que cada insumo precisa para todos os itens (milesimos).
     * Produtos de outro restaurante, arquivados ou pausados recusam a venda.
     * @return array<int,int>
     */
    private function necessidade(int $idRestaurante, array $itens): array
    {
        $ids = array_keys($itens);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $produtos = $this->db->prepare(
            "SELECT id, nome, ativo, disponivel, controla_estoque FROM produtos
             WHERE id_restaurante = ? AND id IN ($marcadores)"
        );
        $produtos->execute([$idRestaurante, ...$ids]);
        $encontrados = [];
        foreach ($produtos->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if ((int) $p['ativo'] !== 1 || (int) $p['disponivel'] !== 1) {
                throw new InvalidArgumentException('Produto indisponivel para venda: ' . $p['nome'] . '.');
            }
            $encontrados[(int) $p['id']] = (int) $p['controla_estoque'] === 1;
        }
        if (count($encontrados) !== count($ids)) {
            throw new InvalidArgumentException('Produto nao encontrado neste restaurante.');
        }

        $controlados = array_keys(array_filter($encontrados));
        if ($controlados === []) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($controlados), '?'));
        $ficha = $this->db->prepare(
            "SELECT id_produto, id_insumo, quantidade FROM produto_insumos
             WHERE id_restaurante = ? AND id_produto IN ($marcadores)"
        );
        $ficha->execute([$idRestaurante, ...$controlados]);
        $necessidade = [];
        foreach ($ficha->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $idInsumo = (int) $linha['id_insumo'];
            $porUnidade = Quantidade::paraMilesimos((string) $linha['quantidade']);
            $necessidade[$idInsumo] = ($necessidade[$idInsumo] ?? 0) + $porUnidade * $itens[(int) $linha['id_produto']];
        }
        ksort($necessidade); // mesma ordem de travamento em todas as vendas: evita deadlock
        return $necessidade;
    }

    /** @return array<int,array<string,mixed>> */
    private function travarInsumos(int $idRestaurante, array $ids): array
    {
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->db->prepare(
            "SELECT id, nome, unidade, estoque_atual, ativo FROM insumos
             WHERE id_restaurante = ? AND id IN ($marcadores) ORDER BY id FOR UPDATE"
        );
        $query->execute([$idRestaurante, ...$ids]);
        $insumos = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insumos[(int) $row['id']] = $row;
        }
        return $insumos;
    }

    /** Usa a transacao do chamador (ex.: criacao do pedido) ou abre uma propria. */
    private function emTransacao(callable $operacao): mixed
    {
        if ($this->db->inTransaction()) {
            return $operacao();
        }
        $this->db->beginTransaction();
        try {
            $resultado = $operacao();
            $this->db->commit();
            return $resultado;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }
}
