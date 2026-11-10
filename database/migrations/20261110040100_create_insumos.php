<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Insumos do estoque. A unidade fica so aqui: a ficha tecnica usa sempre a unidade do insumo.
    // estoque_atual nunca negativo: faltou insumo, o produto fica indisponivel para venda.
    // DECIMAL(14,3): 1 = 1 un, 1 kg ou 1 L; 0.080 = 80 g ou 80 ml.
    $db->exec(<<<'SQL'
CREATE TABLE insumos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    unidade ENUM('un', 'kg', 'l') NOT NULL,
    estoque_atual DECIMAL(14, 3) NOT NULL DEFAULT 0.000,
    estoque_minimo DECIMAL(14, 3) NOT NULL DEFAULT 0.000,
    custo_unitario DECIMAL(14, 4) NULL DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_insumos_id_restaurante (id, id_restaurante),
    UNIQUE KEY uq_insumos_restaurante_nome (id_restaurante, nome),
    KEY idx_insumos_restaurante_ativo (id_restaurante, ativo, nome),
    CONSTRAINT fk_insumos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_insumos_estoque_atual CHECK (estoque_atual >= 0),
    CONSTRAINT chk_insumos_estoque_minimo CHECK (estoque_minimo >= 0),
    CONSTRAINT chk_insumos_custo CHECK (custo_unitario IS NULL OR custo_unitario >= 0),
    CONSTRAINT chk_insumos_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
