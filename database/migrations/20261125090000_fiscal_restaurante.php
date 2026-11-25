<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS restaurante_fiscal (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    cnpj CHAR(14) NOT NULL,
    habilitado TINYINT(1) NOT NULL DEFAULT 0,
    aprovado TINYINT(1) NOT NULL DEFAULT 0,
    id_impressora BIGINT UNSIGNED NULL,
    dados MEDIUMTEXT NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    UNIQUE KEY uq_fiscal_cnpj (cnpj),
    CONSTRAINT fk_fiscal_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
