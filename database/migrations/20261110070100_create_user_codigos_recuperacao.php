<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Codigos de recuperacao da verificacao em duas etapas (uso unico, guardados so como hash).
    $db->exec(<<<'SQL'
CREATE TABLE user_codigos_recuperacao (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_user BIGINT UNSIGNED NOT NULL,
    codigo_hash CHAR(64) NOT NULL,
    usado_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_codigos_recuperacao_hash (id_user, codigo_hash),
    CONSTRAINT fk_codigos_recuperacao_user FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
