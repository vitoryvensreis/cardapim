<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // "Manter conectado": um registro por aparelho. O cookie leva seletor:validador;
    // o banco guarda so o SHA-256 do validador (vazamento do banco nao permite entrar).
    // ON DELETE CASCADE: se a conta for apagada, os acessos lembrados somem junto.
    $db->exec(<<<'SQL'
CREATE TABLE user_sessoes_lembradas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_user BIGINT UNSIGNED NOT NULL,
    seletor CHAR(24) NOT NULL,
    validador_hash CHAR(64) NOT NULL,
    expira_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_uso_em DATETIME NULL DEFAULT NULL,
    ip VARCHAR(45) NULL DEFAULT NULL,
    navegador VARCHAR(120) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessoes_lembradas_seletor (seletor),
    KEY idx_sessoes_lembradas_user (id_user, id),
    KEY idx_sessoes_lembradas_expira (expira_em),
    CONSTRAINT fk_sessoes_lembradas_user FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
