<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Como o restaurante recebe pedidos online: entrega e/ou retirada, tempo estimado e formas de pagamento aceitas.
    // Uma linha por restaurante (criada ao salvar a aba "Entrega e pagamento"). Sem linha = pedidos online desligados.
    // pag_pix_online so funciona com o Asaas do restaurante conectado (conferido no codigo, nao no banco).
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_entrega_pagamento (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    aceita_entrega TINYINT(1) NOT NULL DEFAULT 0,
    aceita_retirada TINYINT(1) NOT NULL DEFAULT 0,
    entrega_minutos_min SMALLINT UNSIGNED NULL DEFAULT NULL,
    entrega_minutos_max SMALLINT UNSIGNED NULL DEFAULT NULL,
    retirada_minutos SMALLINT UNSIGNED NULL DEFAULT NULL,
    pag_pix_online TINYINT(1) NOT NULL DEFAULT 0,
    pag_pix_entrega TINYINT(1) NOT NULL DEFAULT 0,
    pag_debito TINYINT(1) NOT NULL DEFAULT 0,
    pag_credito TINYINT(1) NOT NULL DEFAULT 0,
    pag_dinheiro TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_entrega_pagamento_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_entrega_pagamento_flags CHECK (
        aceita_entrega IN (0, 1) AND aceita_retirada IN (0, 1) AND pag_pix_online IN (0, 1) AND pag_pix_entrega IN (0, 1)
        AND pag_debito IN (0, 1) AND pag_credito IN (0, 1) AND pag_dinheiro IN (0, 1)
    ),
    CONSTRAINT chk_entrega_pagamento_tempo CHECK (
        (entrega_minutos_min IS NULL OR entrega_minutos_min BETWEEN 1 AND 600)
        AND (entrega_minutos_max IS NULL OR entrega_minutos_max BETWEEN 1 AND 600)
        AND (entrega_minutos_min IS NULL OR entrega_minutos_max IS NULL OR entrega_minutos_max >= entrega_minutos_min)
        AND (retirada_minutos IS NULL OR retirada_minutos BETWEEN 1 AND 600)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Areas de entrega: por BAIRRO (nome + cidade) ou por RAIO (ate X km do restaurante), cada uma com taxa e
    // pedido minimo opcional. Bairro cadastrado tem prioridade; senao vale a menor faixa de km que alcanca o endereco.
    // chave: bairro e cidade normalizados ("aldeota|fortaleza") ou o raio ("3.00"): impede duplicar a mesma area.
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_areas_entrega (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    tipo ENUM('bairro', 'raio') NOT NULL,
    bairro VARCHAR(100) NULL DEFAULT NULL,
    cidade VARCHAR(100) NULL DEFAULT NULL,
    raio_km DECIMAL(5,2) NULL DEFAULT NULL,
    chave VARCHAR(210) NOT NULL,
    taxa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pedido_minimo DECIMAL(10,2) NULL DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_areas_entrega_chave (id_restaurante, tipo, chave),
    UNIQUE KEY uq_areas_entrega_id_restaurante (id, id_restaurante),
    CONSTRAINT fk_areas_entrega_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_areas_entrega_tipo CHECK (
        (tipo = 'bairro' AND bairro IS NOT NULL AND CHAR_LENGTH(TRIM(bairro)) >= 2 AND raio_km IS NULL)
        OR (tipo = 'raio' AND raio_km IS NOT NULL AND raio_km BETWEEN 0.10 AND 100.00 AND bairro IS NULL)
    ),
    CONSTRAINT chk_areas_entrega_valores CHECK (taxa >= 0 AND (pedido_minimo IS NULL OR pedido_minimo >= 0)),
    CONSTRAINT chk_areas_entrega_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
