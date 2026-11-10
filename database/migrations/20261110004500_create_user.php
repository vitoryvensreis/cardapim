<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE `user` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NULL,
    cargo ENUM('cliente', 'gerente', 'motoqueiro', 'garcom', 'caixa', 'adm_master') NOT NULL,
    usuario VARCHAR(60) NOT NULL,
    email VARCHAR(190) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    sobrenome VARCHAR(150) NOT NULL,
    whatsapp VARCHAR(25) NULL,
    ultimo_acesso DATETIME NULL DEFAULT NULL,
    ultimo_codigo_hash VARCHAR(255) NULL DEFAULT NULL,
    ultimo_codigo_expira_em DATETIME NULL DEFAULT NULL,
    email_verificado_em DATETIME NULL DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_usuario (usuario),
    UNIQUE KEY uq_user_email (email),
    KEY idx_user_restaurante_cargo (id_restaurante, cargo),
    KEY idx_user_restaurante_ativo (id_restaurante, ativo),
    CONSTRAINT fk_user_restaurante FOREIGN KEY (id_restaurante)
        REFERENCES restaurantes (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_user_escopo CHECK (
        (cargo = 'adm_master' AND id_restaurante IS NULL)
        OR (cargo <> 'adm_master' AND id_restaurante IS NOT NULL)
    ),
    CONSTRAINT chk_user_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
