<?php

declare(strict_types=1);

/**
 * Uma leitura recente por agente Windows, sem histórico de pesos de clientes.
 * O PHP somente sugere o peso: o lançamento da comanda segue o fluxo existente.
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE desktop_agent_pesagens (
    id_dispositivo BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    peso_kg DECIMAL(5,3) NULL,
    estavel TINYINT(1) NOT NULL DEFAULT 0,
    atualizado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id_dispositivo),
    KEY idx_desktop_pesagens_restaurante (id_restaurante, atualizado_em),
    CONSTRAINT ck_desktop_pesagens_peso CHECK (peso_kg IS NULL OR (peso_kg >= 0 AND peso_kg <= 99.999)),
    CONSTRAINT fk_desktop_pesagens_device FOREIGN KEY (id_dispositivo, id_restaurante)
        REFERENCES desktop_agent_devices (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT fk_desktop_pesagens_restaurante FOREIGN KEY (id_restaurante)
        REFERENCES restaurantes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
