<?php

declare(strict_types=1);

/**
 * Produto especial "Meio a Meio" (ex.: pizza meia calabresa, meia mista):
 * - produto_meio_a_meio: o produto e Meio a Meio; minimo/maximo de sabores (1 a 4, podem ser iguais); regra do preco
 *   (fracionado = soma dos sabores / quantidade, maior = sabor mais caro, personalizado = preco do proprio produto);
 *   id_categoria = os sabores sao os produtos desta categoria (NULL = os produtos escolhidos em produto_meio_sabores).
 * - produto_meio_sabores: sabores escolhidos um a um. Criada por ultimo (Esquema::tem 'meio_a_meio').
 * Cada sabor baixa a fracao do proprio estoque (2 sabores = 0,5 de cada). Nao muda pedidos nem itens.
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE produto_meio_a_meio (
    id_produto BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    minimo TINYINT UNSIGNED NOT NULL DEFAULT 1,
    maximo TINYINT UNSIGNED NOT NULL DEFAULT 2,
    regra ENUM('fracionado', 'maior', 'personalizado') NOT NULL DEFAULT 'fracionado',
    id_categoria BIGINT UNSIGNED NULL DEFAULT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_produto),
    KEY idx_meio_restaurante (id_restaurante),
    KEY idx_meio_categoria (id_categoria),
    CONSTRAINT fk_meio_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT fk_meio_categoria FOREIGN KEY (id_categoria) REFERENCES categorias (id) ON DELETE SET NULL,
    CONSTRAINT chk_meio_quantidade CHECK (minimo BETWEEN 1 AND 4 AND maximo BETWEEN 1 AND 4 AND minimo <= maximo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE produto_meio_sabores (
    id_produto BIGINT UNSIGNED NOT NULL,
    id_sabor BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id_produto, id_sabor),
    KEY idx_meio_sabores_sabor (id_sabor, id_restaurante),
    CONSTRAINT fk_meio_sabores_meio FOREIGN KEY (id_produto) REFERENCES produto_meio_a_meio (id_produto) ON DELETE CASCADE,
    CONSTRAINT fk_meio_sabores_sabor FOREIGN KEY (id_sabor, id_restaurante) REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
