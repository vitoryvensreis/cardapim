<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // CUPONS do restaurante: codigo digitado pelo cliente no cardapio online (desconto em % ou valor fixo).
    // limite_usos: quantas vezes no total (NULL = sem limite; 1 = so uma vez). um_por_cliente: cada cliente usa uma vez.
    // Usos = pedidos com id_cupom que nao foram cancelados (nao ha contador separado para nao dessincronizar).
    $db->exec(<<<'SQL'
CREATE TABLE cupons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    tipo ENUM('percentual', 'fixo') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    pedido_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    limite_usos INT UNSIGNED NULL DEFAULT NULL,
    um_por_cliente TINYINT(1) NOT NULL DEFAULT 0,
    valido_ate DATE NULL DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cupons_codigo (id_restaurante, codigo),
    UNIQUE KEY uq_cupons_id_restaurante (id, id_restaurante),
    CONSTRAINT fk_cupons_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_cupons_codigo CHECK (CHAR_LENGTH(codigo) BETWEEN 3 AND 20),
    CONSTRAINT chk_cupons_valor CHECK (valor > 0 AND (tipo <> 'percentual' OR valor <= 100)),
    CONSTRAINT chk_cupons_minimo CHECK (pedido_minimo >= 0),
    CONSTRAINT chk_cupons_limite CHECK (limite_usos IS NULL OR limite_usos >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // FIDELIDADE: uma linha por restaurante. A cada "compras_necessarias" pedidos concluidos (de qualquer valor ou
    // com itens a partir de valor_minimo), o proximo pedido do cliente no cardapio online ganha o desconto.
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_fidelidade (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    compras_necessarias SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    tipo ENUM('percentual', 'fixo') NOT NULL DEFAULT 'percentual',
    valor DECIMAL(10,2) NOT NULL DEFAULT 10.00,
    valor_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_restaurante_fidelidade_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_fidelidade_compras CHECK (compras_necessarias BETWEEN 1 AND 100),
    CONSTRAINT chk_fidelidade_valor CHECK (valor > 0 AND (tipo <> 'percentual' OR valor <= 100)),
    CONSTRAINT chk_fidelidade_minimo CHECK (valor_minimo >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // De onde veio o desconto do pedido (cupom ou fidelidade) e qual cupom foi usado.
    $db->exec(<<<'SQL'
ALTER TABLE pedidos
    ADD COLUMN desconto_origem ENUM('cupom', 'fidelidade') NULL DEFAULT NULL AFTER desconto,
    ADD COLUMN id_cupom BIGINT UNSIGNED NULL DEFAULT NULL AFTER desconto_origem,
    ADD COLUMN cupom_codigo VARCHAR(20) NULL DEFAULT NULL AFTER id_cupom,
    ADD KEY idx_pedidos_cupom (id_cupom, id_restaurante),
    ADD KEY idx_pedidos_fidelidade (id_restaurante, id_cliente, desconto_origem),
    ADD CONSTRAINT fk_pedidos_cupom FOREIGN KEY (id_cupom, id_restaurante) REFERENCES cupons (id, id_restaurante)
SQL);
};
