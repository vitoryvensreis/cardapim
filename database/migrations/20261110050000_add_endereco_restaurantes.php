<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Endereco do restaurante (todas opcionais: restaurantes existentes e cadastros pendentes).
    // cep somente com 8 digitos; estado = sigla da UF em maiusculas (comparacao binaria,
    // a collation padrao ignoraria maiusculas); numero aceita "S/N" e "120A".
    // latitude/longitude preparadas para entregas e mapa (preenchidas juntas ou ambas vazias;
    // IS NOT NULL explicito porque CHECK com resultado NULL seria aceito).
    $db->exec(<<<'SQL'
ALTER TABLE restaurantes
    ADD COLUMN cep CHAR(8) NULL DEFAULT NULL AFTER cnpj,
    ADD COLUMN logradouro VARCHAR(180) NULL DEFAULT NULL AFTER cep,
    ADD COLUMN numero VARCHAR(20) NULL DEFAULT NULL AFTER logradouro,
    ADD COLUMN complemento VARCHAR(120) NULL DEFAULT NULL AFTER numero,
    ADD COLUMN bairro VARCHAR(120) NULL DEFAULT NULL AFTER complemento,
    ADD COLUMN cidade VARCHAR(120) NULL DEFAULT NULL AFTER bairro,
    ADD COLUMN estado CHAR(2) NULL DEFAULT NULL AFTER cidade,
    ADD COLUMN ponto_referencia VARCHAR(180) NULL DEFAULT NULL AFTER estado,
    ADD COLUMN latitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER ponto_referencia,
    ADD COLUMN longitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER latitude,
    ADD KEY idx_restaurantes_localidade (estado, cidade),
    ADD CONSTRAINT chk_restaurantes_cep CHECK (cep IS NULL OR cep REGEXP '^[0-9]{8}$'),
    ADD CONSTRAINT chk_restaurantes_estado CHECK (estado IS NULL OR (estado COLLATE utf8mb4_bin) IN (
        'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR',
        'PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'
    )),
    ADD CONSTRAINT chk_restaurantes_coordenadas CHECK (
        (latitude IS NULL AND longitude IS NULL)
        OR (latitude IS NOT NULL AND longitude IS NOT NULL
            AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
    )
SQL);
};
