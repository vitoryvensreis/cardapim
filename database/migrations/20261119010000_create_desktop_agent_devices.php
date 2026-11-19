<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE desktop_agent_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_user BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(90) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expira_em DATETIME NOT NULL,
    revogado_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_acesso_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_desktop_agent_token (token_hash),
    KEY idx_desktop_agent_usuario (id_user, id_restaurante),
    CONSTRAINT fk_desktop_agent_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT fk_desktop_agent_usuario FOREIGN KEY (id_user) REFERENCES \`user\` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
