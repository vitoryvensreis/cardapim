<?php

declare(strict_types=1);

/**
 * Limite de escolhas de cada grupo DENTRO de um combo (vale no lugar do minimo/maximo do grupo de complemento,
 * que pode ser usado em outros produtos com outro limite). Sem linha: opcional (minimo 0) e o maximo do grupo.
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE combo_grupos (
    id_combo BIGINT UNSIGNED NOT NULL,
    id_grupo BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    minimo TINYINT UNSIGNED NOT NULL DEFAULT 0,
    maximo TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id_combo, id_grupo),
    KEY idx_combo_grupos_grupo (id_grupo, id_restaurante),
    CONSTRAINT fk_combo_grupos_combo FOREIGN KEY (id_combo) REFERENCES combos (id_produto) ON DELETE CASCADE,
    CONSTRAINT fk_combo_grupos_grupo FOREIGN KEY (id_grupo, id_restaurante) REFERENCES complemento_grupos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT chk_combo_grupos_limites CHECK (maximo BETWEEN 1 AND 30 AND minimo <= maximo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
