<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use Cardapim\Services\Pedidos\TaxaPagamento;
use Cardapim\Shared\Esquema;
use PDO;

/** Taxa opcional de cada forma de pagamento do cardapio online (restaurante_taxas_pagamento). */
final class TaxaPagamentoRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function disponivel(): bool
    {
        return Esquema::tem($this->db, 'taxas_pagamento');
    }

    /** @return array<string,array{tipo:string,valor:string}> forma => regra (so as formas com taxa) */
    public function regras(int $idRestaurante): array
    {
        if (!$this->disponivel()) {
            return [];
        }
        $q = $this->db->prepare('SELECT forma, tipo, valor FROM restaurante_taxas_pagamento WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $regras = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset(TaxaPagamento::FORMAS[$r['forma']])) {
                $regras[(string) $r['forma']] = ['tipo' => (string) $r['tipo'], 'valor' => (string) $r['valor']];
            }
        }
        return $regras;
    }

    /** Troca todas as regras do restaurante (forma ausente = sem taxa). @param array<string,array{tipo:string,valor:string}> $regras */
    public function salvar(int $idRestaurante, array $regras): void
    {
        if (!$this->disponivel()) {
            return;
        }
        $dono = !$this->db->inTransaction();
        if ($dono) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('DELETE FROM restaurante_taxas_pagamento WHERE id_restaurante = ?')->execute([$idRestaurante]);
            $ins = $this->db->prepare('INSERT INTO restaurante_taxas_pagamento (id_restaurante, forma, tipo, valor) VALUES (?, ?, ?, ?)');
            foreach ($regras as $forma => $r) {
                if (isset(TaxaPagamento::FORMAS[$forma])) {
                    $ins->execute([$idRestaurante, $forma, $r['tipo'], $r['valor']]);
                }
            }
            if ($dono) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($dono && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
