<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS restaurantes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(180) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    telefone VARCHAR(25) NULL,
    email VARCHAR(190) NULL,
    cnpj CHAR(14) NULL,
    plano_id BIGINT UNSIGNED NULL,
    status_rest ENUM('pendente', 'ativo', 'suspenso', 'inativo') NOT NULL DEFAULT 'pendente',
    logo_path VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_restaurantes_slug (slug),
    UNIQUE KEY uq_restaurantes_cnpj (cnpj),
    KEY idx_restaurantes_plano_id (plano_id),
    KEY idx_restaurantes_status_rest (status_rest),
    CONSTRAINT fk_restaurantes_plano FOREIGN KEY (plano_id)
        REFERENCES planos (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
