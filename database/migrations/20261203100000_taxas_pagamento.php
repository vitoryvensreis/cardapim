<?php

declare(strict_types=1);

/**
 * Taxa opcional por forma de pagamento nos pedidos do cardapio online (porcentagem OU valor fixo; dinheiro nunca tem taxa).
 * - pedidos.taxa_pagamento: valor cobrado no pedido (0 = sem taxa). Entra no total (CHECK refeito com a coluna).
 * - pedidos.taxa_pagamento_rotulo: foto da regra no momento do pedido (ex.: "Taxa do crédito (3,5%)").
 * - restaurante_taxas_pagamento: a regra de cada forma. Sem linha = sem taxa. Criada por ultimo (Esquema::tem 'taxas_pagamento').
 */
return static function (PDO $db): void {
    // O MySQL faz commit implicito no DDL. Se a primeira alteracao terminou e a
    // criacao da tabela falhou, a proxima execucao deve continuar sem repetir o ALTER.
    $q = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
        AND COLUMN_NAME IN ('taxa_pagamento', 'taxa_pagamento_rotulo')");
    $colunas = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($colunas === []) {
        $db->exec(<<<'SQL'
ALTER TABLE pedidos
    ADD COLUMN taxa_pagamento DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER taxa_servico,
    ADD COLUMN taxa_pagamento_rotulo VARCHAR(60) NULL DEFAULT NULL AFTER taxa_pagamento,
    DROP CHECK chk_pedidos_valores,
    ADD CONSTRAINT chk_pedidos_valores CHECK (
        subtotal >= 0 AND taxa_entrega >= 0 AND taxa_servico >= 0 AND taxa_pagamento >= 0 AND desconto >= 0 AND total >= 0
        AND total = subtotal + taxa_entrega + taxa_servico + taxa_pagamento - desconto
    )
SQL);
    } elseif (count($colunas) !== 2) {
        throw new RuntimeException('Schema parcial inesperado nas colunas da taxa de pagamento.');
    }
    // Nao ativa o recurso se as colunas existirem com uma constraint antiga.
    $check = $db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_pedidos_valores'")->fetchColumn();
    if (!str_contains((string) $check, 'taxa_pagamento')) {
        throw new RuntimeException('CHECK dos pedidos nao inclui a taxa de pagamento.');
    }
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS restaurante_taxas_pagamento (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    forma ENUM('pix_online', 'pix_comprovante', 'pix_entrega', 'debito', 'credito') NOT NULL,
    tipo ENUM('percentual', 'fixo') NOT NULL,
    valor DECIMAL(7,2) NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante, forma),
    CONSTRAINT fk_taxas_pagamento_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT chk_taxas_pagamento_valor CHECK (
        (tipo = 'percentual' AND valor > 0 AND valor <= 20) OR (tipo = 'fixo' AND valor > 0 AND valor <= 50)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
