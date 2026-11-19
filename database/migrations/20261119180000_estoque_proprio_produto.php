<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Estoque contado no proprio produto (revenda: refrigerante, agua, cerveja lata).
    // O produto ganha um insumo "proprio", ligado a ele, com ficha de 1 unidade (ou 1 kg/L):
    // baixa, devolucao, disponibilidade, iFood e resumo do estoque continuam pela mesma regra.
    // Sem FK de proposito: o insumo proprio e arquivado pelo codigo quando o produto muda ou sai.
    $db->exec(<<<'SQL'
ALTER TABLE insumos
    ADD COLUMN id_produto_proprio BIGINT UNSIGNED NULL DEFAULT NULL AFTER ativo,
    ADD UNIQUE KEY uq_insumos_produto_proprio (id_restaurante, id_produto_proprio)
SQL);
};
