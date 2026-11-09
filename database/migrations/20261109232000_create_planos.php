<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS planos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    descricao TEXT NULL,
    valor DECIMAL(10, 2) NOT NULL,
    periodo ENUM('mensal', 'semestral', 'anual') NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_planos_nome_periodo (nome, periodo),
    CONSTRAINT chk_planos_valor CHECK (valor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
