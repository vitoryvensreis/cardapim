<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Ficha tecnica: quanto de cada insumo vai em 1 unidade do produto (na unidade do insumo).
    // FKs compostas com id_restaurante: o banco impede produto de um restaurante usar
    // insumo de outro, mesmo que o codigo erre.
    $db->exec(<<<'SQL'
CREATE TABLE produto_insumos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NOT NULL,
    id_insumo BIGINT UNSIGNED NOT NULL,
    quantidade DECIMAL(12, 3) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_produto_insumos_item (id_produto, id_insumo),
    KEY idx_produto_insumos_produto (id_produto, id_restaurante),
    KEY idx_produto_insumos_insumo (id_insumo, id_restaurante),
    CONSTRAINT fk_produto_insumos_produto FOREIGN KEY (id_produto, id_restaurante)
        REFERENCES produtos (id, id_restaurante),
    CONSTRAINT fk_produto_insumos_insumo FOREIGN KEY (id_insumo, id_restaurante)
        REFERENCES insumos (id, id_restaurante),
    CONSTRAINT chk_produto_insumos_quantidade CHECK (quantidade > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
