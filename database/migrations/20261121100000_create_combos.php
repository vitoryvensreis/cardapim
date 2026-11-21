<?php

declare(strict_types=1);

/**
 * Combos: produto do cardapio que, ao ser lancado, vira os itens separados (estoque e impressao de cada um),
 * com o preco do combo dividido entre eles. So tabelas e colunas novas: produto comum continua igual.
 * - combos: o produto e um combo; maximo = limite total de itens (inclusos + escolhidos), NULL = sem limite total.
 * - combo_itens: itens obrigatorios (ja inclusos) do combo, com quantidade.
 * - combo_opcoes: para as opcoes dos grupos de complemento usadas em combos: produto ligado (estoque/impressao
 *   do produto) e maximo desta opcao por combo (ex.: picanha no maximo 2). NULL = sem produto / sem limite.
 * - pedido_itens.combo_lote / combo_nome: as linhas que vieram do mesmo combo (cancela junto, mostra agrupado).
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE combos (
    id_produto BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    maximo TINYINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id_produto),
    KEY idx_combos_restaurante (id_restaurante),
    CONSTRAINT fk_combos_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT chk_combos_maximo CHECK (maximo IS NULL OR maximo BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE combo_itens (
    id_combo BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    quantidade TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id_combo, id_produto),
    KEY idx_combo_itens_produto (id_produto, id_restaurante),
    CONSTRAINT fk_combo_itens_combo FOREIGN KEY (id_combo) REFERENCES combos (id_produto) ON DELETE CASCADE,
    CONSTRAINT fk_combo_itens_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT chk_combo_itens_quantidade CHECK (quantidade BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE combo_opcoes (
    id_complemento BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NULL DEFAULT NULL,
    maximo TINYINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id_complemento),
    KEY idx_combo_opcoes_produto (id_produto, id_restaurante),
    CONSTRAINT fk_combo_opcoes_complemento FOREIGN KEY (id_complemento, id_restaurante) REFERENCES complementos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT fk_combo_opcoes_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT chk_combo_opcoes_maximo CHECK (maximo IS NULL OR maximo BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec('ALTER TABLE pedido_itens
        ADD COLUMN combo_lote CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
        ADD COLUMN combo_nome VARCHAR(150) NULL DEFAULT NULL,
        ADD KEY idx_pedido_itens_combo (id_pedido, combo_lote)');
};
