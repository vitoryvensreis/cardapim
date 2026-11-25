<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_perfis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    versao INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fp_nome (id_restaurante,nome),
    UNIQUE KEY uq_fp_tenant (id_restaurante,id),
    CONSTRAINT fk_fp_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_perfil_versoes (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_perfil BIGINT UNSIGNED NOT NULL,
    versao INT UNSIGNED NOT NULL,
    dados JSON NOT NULL,
    revisado TINYINT(1) NOT NULL DEFAULT 0,
    autor BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante,id_perfil,versao),
    CONSTRAINT fk_fpv_perfil FOREIGN KEY (id_restaurante,id_perfil) REFERENCES fiscal_perfis(id_restaurante,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_categoria_perfil (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_categoria BIGINT UNSIGNED NOT NULL,
    id_perfil BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id_restaurante,id_categoria),
    CONSTRAINT fk_fcp_perfil FOREIGN KEY (id_restaurante,id_perfil) REFERENCES fiscal_perfis(id_restaurante,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_produtos (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NOT NULL,
    id_perfil BIGINT UNSIGNED NULL,
    versao INT UNSIGNED NULL,
    dados JSON NOT NULL,
    autor BIGINT UNSIGNED NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante,id_produto),
    KEY idx_fprod_perfil (id_restaurante,id_perfil),
    CONSTRAINT fk_fprod_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
