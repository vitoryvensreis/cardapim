<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_catalogos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo VARCHAR(24) NOT NULL,
    versao VARCHAR(100) NOT NULL,
    vigencia DATE NOT NULL,
    dados MEDIUMTEXT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    autor BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogo_versao (tipo,versao),
    KEY idx_catalogo_vigencia (tipo,vigencia,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_composicoes (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NOT NULL,
    assinatura CHAR(64) NOT NULL,
    dados JSON NOT NULL,
    autor BIGINT UNSIGNED NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante,id_produto),
    CONSTRAINT fk_fcomp_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
