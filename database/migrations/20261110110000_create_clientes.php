<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Cliente final (quem pede pelo cardapio /{slug}). A conta e da PLATAFORMA: o mesmo cliente pede em
    // qualquer restaurante. Entra so com o WhatsApp: o codigo chega pelo WhatsApp da plataforma.
    // whatsapp: DDD + numero, so digitos (10 ou 11), sem o 55. Unico: um cadastro por numero.
    // cpf: opcional (pedido de nota/pagamento), so digitos, unico quando informado. Exibir sempre mascarado.
    // Endereco completo (entrega): mesmas regras da tabela restaurantes. Coordenadas: as duas juntas.
    $db->exec(<<<'SQL'
CREATE TABLE clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(80) NOT NULL,
    whatsapp CHAR(11) NOT NULL,
    cpf CHAR(11) NULL DEFAULT NULL,
    cep CHAR(8) NULL DEFAULT NULL,
    logradouro VARCHAR(150) NULL DEFAULT NULL,
    numero VARCHAR(20) NULL DEFAULT NULL,
    complemento VARCHAR(100) NULL DEFAULT NULL,
    bairro VARCHAR(100) NULL DEFAULT NULL,
    cidade VARCHAR(100) NULL DEFAULT NULL,
    estado CHAR(2) NULL DEFAULT NULL,
    ponto_referencia VARCHAR(150) NULL DEFAULT NULL,
    latitude DECIMAL(10,7) NULL DEFAULT NULL,
    longitude DECIMAL(10,7) NULL DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    whatsapp_verificado_em DATETIME NULL DEFAULT NULL,
    ultimo_acesso DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_whatsapp (whatsapp),
    UNIQUE KEY uq_clientes_cpf (cpf),
    KEY idx_clientes_ultimo_acesso (ultimo_acesso),
    CONSTRAINT chk_clientes_nome CHECK (CHAR_LENGTH(TRIM(nome)) >= 2),
    CONSTRAINT chk_clientes_whatsapp CHECK ((whatsapp COLLATE utf8mb4_bin) REGEXP '^[1-9][0-9]{9,10}$'),
    CONSTRAINT chk_clientes_cpf CHECK (cpf IS NULL OR (cpf COLLATE utf8mb4_bin) REGEXP '^[0-9]{11}$'),
    CONSTRAINT chk_clientes_cep CHECK (cep IS NULL OR (cep COLLATE utf8mb4_bin) REGEXP '^[0-9]{8}$'),
    CONSTRAINT chk_clientes_estado CHECK (estado IS NULL OR (estado COLLATE utf8mb4_bin) IN
        ('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO')),
    CONSTRAINT chk_clientes_coordenadas CHECK (
        (latitude IS NULL AND longitude IS NULL)
        OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Cliente continua conectado no aparelho (como nos apps de delivery): seletor:validador no cookie,
    // so o SHA-256 do validador no banco, troca a cada uso. Cliente apagado -> acessos somem junto.
    $db->exec(<<<'SQL'
CREATE TABLE cliente_sessoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente BIGINT UNSIGNED NOT NULL,
    seletor CHAR(24) NOT NULL,
    validador_hash CHAR(64) NOT NULL,
    expira_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_uso_em DATETIME NULL DEFAULT NULL,
    ip VARCHAR(45) NULL DEFAULT NULL,
    navegador VARCHAR(120) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cliente_sessoes_seletor (seletor),
    KEY idx_cliente_sessoes_cliente (id_cliente, id),
    KEY idx_cliente_sessoes_expira (expira_em),
    CONSTRAINT fk_cliente_sessoes_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
