<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_visibilidade (
    id TINYINT UNSIGNED NOT NULL,
    visivel TINYINT(1) NOT NULL DEFAULT 1,
    autor BIGINT UNSIGNED NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec('INSERT IGNORE INTO fiscal_visibilidade (id,visivel) VALUES (1,1)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_liberacoes (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    permitido TINYINT(1) NOT NULL DEFAULT 0,
    autor BIGINT UNSIGNED NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_fiscal_liberacoes_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    // Nenhuma autorização implícita. Configurações antigas continuam armazenadas.
};
