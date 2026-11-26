<?php
declare(strict_types=1);

/**
 * Emissão de NFC-e (etapas 04 a 07). Tabelas aditivas: nenhuma tabela comercial é alterada.
 * - fiscal_documentos: uma NFC-e por pedido. "dados" guarda a foto imutável da venda no momento da emissão.
 *   status: pendente (na fila), enviando, incerta (sem resposta: consultar antes de reenviar), autorizada,
 *   rejeitada (SEFAZ/NeXT recusou; pode corrigir e reenviar com o mesmo número), bloqueada (faltam dados,
 *   nada foi enviado), cancelada.
 * - fiscal_numeracao: próximo número por restaurante, ambiente e série (reserva com SELECT ... FOR UPDATE).
 * - fiscal_eventos: cancelamentos e inutilizações, com protocolo.
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_numeracao (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ambiente TINYINT UNSIGNED NOT NULL,
    serie SMALLINT UNSIGNED NOT NULL,
    proximo INT UNSIGNED NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante, ambiente, serie),
    CONSTRAINT fk_fnum_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id) ON DELETE CASCADE,
    CONSTRAINT chk_fnum CHECK (ambiente BETWEEN 1 AND 3 AND serie <= 999 AND proximo >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_pedido BIGINT UNSIGNED NOT NULL,
    origem VARCHAR(16) NOT NULL,
    ambiente TINYINT UNSIGNED NOT NULL,
    serie SMALLINT UNSIGNED NULL DEFAULT NULL,
    numero INT UNSIGNED NULL DEFAULT NULL,
    codigo_nf CHAR(8) NULL DEFAULT NULL,
    chave CHAR(44) NULL DEFAULT NULL,
    status ENUM('pendente','enviando','incerta','autorizada','rejeitada','bloqueada','cancelada') NOT NULL DEFAULT 'pendente',
    cod_status SMALLINT UNSIGNED NULL DEFAULT NULL,
    motivo VARCHAR(400) NULL DEFAULT NULL,
    protocolo VARCHAR(30) NULL DEFAULT NULL,
    cpf_cnpj VARCHAR(14) NULL DEFAULT NULL,
    valor DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    dados MEDIUMTEXT NULL DEFAULT NULL,
    qrcode VARCHAR(1000) NULL DEFAULT NULL,
    url_consulta VARCHAR(300) NULL DEFAULT NULL,
    xml_arquivo VARCHAR(255) NULL DEFAULT NULL,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NULL DEFAULT NULL,
    emitida_em DATETIME NULL DEFAULT NULL,
    autorizada_em DATETIME NULL DEFAULT NULL,
    cancelada_em DATETIME NULL DEFAULT NULL,
    impressa_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fdoc_pedido (id_restaurante, id_pedido),
    UNIQUE KEY uq_fdoc_numero (id_restaurante, ambiente, serie, numero),
    UNIQUE KEY uq_fdoc_chave (chave),
    KEY idx_fdoc_fila (status, proxima_tentativa_em),
    KEY idx_fdoc_lista (id_restaurante, criado_em),
    CONSTRAINT fk_fdoc_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id),
    CONSTRAINT fk_fdoc_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos(id, id_restaurante),
    CONSTRAINT chk_fdoc_amb CHECK (ambiente BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fiscal_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_documento BIGINT UNSIGNED NULL DEFAULT NULL,
    tipo ENUM('cancelamento','inutilizacao') NOT NULL,
    ambiente TINYINT UNSIGNED NOT NULL,
    serie SMALLINT UNSIGNED NULL DEFAULT NULL,
    numero_inicial INT UNSIGNED NULL DEFAULT NULL,
    numero_final INT UNSIGNED NULL DEFAULT NULL,
    justificativa VARCHAR(255) NOT NULL,
    cod_status SMALLINT UNSIGNED NULL DEFAULT NULL,
    motivo VARCHAR(400) NULL DEFAULT NULL,
    protocolo VARCHAR(30) NULL DEFAULT NULL,
    autor BIGINT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fevt_rest (id_restaurante, criado_em),
    CONSTRAINT fk_fevt_rest FOREIGN KEY (id_restaurante) REFERENCES restaurantes(id),
    CONSTRAINT fk_fevt_doc FOREIGN KEY (id_documento) REFERENCES fiscal_documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
