<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Categorias do cardapio, sempre de um restaurante. UNIQUE(id, id_restaurante) permite
    // FKs compostas: um produto so pode usar categoria do proprio restaurante.
    $db->exec(<<<'SQL'
CREATE TABLE categorias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(80) NOT NULL,
    descricao VARCHAR(255) NULL,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_id_restaurante (id, id_restaurante),
    UNIQUE KEY uq_categorias_restaurante_nome (id_restaurante, nome),
    KEY idx_categorias_restaurante_ordem (id_restaurante, ativo, ordem),
    CONSTRAINT fk_categorias_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_categorias_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
