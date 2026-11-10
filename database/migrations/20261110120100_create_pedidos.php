<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // PEDIDOS. Todo pedido diz de qual RESTAURANTE e (id_restaurante, obrigatorio) e de quem:
    // - id_cliente: cliente final (cardapio online, iFood, WhatsApp). NULL em venda de balcao sem cadastro.
    // - id_user: pessoa da equipe que registrou (caixa, garcom). NULL quando o proprio cliente fez o pedido.
    // numero: sequencia por restaurante (o "pedido #42" que a equipe e o cliente falam).
    // Valores em DECIMAL; total = subtotal + taxa_entrega - desconto (CHECK).
    // Nome, WhatsApp e endereco sao COPIADOS no pedido: o historico nao muda se o cliente mudar o cadastro.
    // consumo_estoque: o que foi baixado dos insumos (JSON id_insumo => quantidade), para devolver no cancelamento.
    // chave_idempotencia: o mesmo envio repetido (clique duplo, internet ruim) nao cria dois pedidos.
    $db->exec(<<<'SQL'
CREATE TABLE pedidos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    numero INT UNSIGNED NOT NULL,
    id_cliente BIGINT UNSIGNED NULL DEFAULT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    origem ENUM('cardapio', 'balcao', 'mesa', 'ifood', 'whatsapp') NOT NULL DEFAULT 'cardapio',
    tipo ENUM('entrega', 'retirada', 'balcao', 'mesa') NOT NULL,
    status ENUM('aguardando_pagamento', 'recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido', 'cancelado') NOT NULL,
    forma_pagamento ENUM('pix_online', 'pix_entrega', 'debito', 'credito', 'dinheiro') NOT NULL,
    pagamento_status ENUM('pendente', 'pago', 'cancelado', 'estornado') NOT NULL DEFAULT 'pendente',
    troco_para DECIMAL(10,2) NULL DEFAULT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    taxa_entrega DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    desconto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    observacao VARCHAR(300) NULL DEFAULT NULL,
    cliente_nome VARCHAR(80) NULL DEFAULT NULL,
    cliente_whatsapp CHAR(11) NULL DEFAULT NULL,
    entrega_cep CHAR(8) NULL DEFAULT NULL,
    entrega_logradouro VARCHAR(150) NULL DEFAULT NULL,
    entrega_numero VARCHAR(20) NULL DEFAULT NULL,
    entrega_complemento VARCHAR(100) NULL DEFAULT NULL,
    entrega_bairro VARCHAR(100) NULL DEFAULT NULL,
    entrega_cidade VARCHAR(100) NULL DEFAULT NULL,
    entrega_estado CHAR(2) NULL DEFAULT NULL,
    entrega_referencia VARCHAR(150) NULL DEFAULT NULL,
    entrega_latitude DECIMAL(10,7) NULL DEFAULT NULL,
    entrega_longitude DECIMAL(10,7) NULL DEFAULT NULL,
    entrega_distancia_km DECIMAL(6,2) NULL DEFAULT NULL,
    id_area_entrega BIGINT UNSIGNED NULL DEFAULT NULL,
    area_descricao VARCHAR(140) NULL DEFAULT NULL,
    previsao_minutos_min SMALLINT UNSIGNED NULL DEFAULT NULL,
    previsao_minutos_max SMALLINT UNSIGNED NULL DEFAULT NULL,
    consumo_estoque TEXT NULL DEFAULT NULL,
    chave_idempotencia CHAR(32) NOT NULL,
    pix_cobranca_id VARCHAR(60) NULL DEFAULT NULL,
    pix_copia_cola TEXT NULL DEFAULT NULL,
    pix_expira_em DATETIME NULL DEFAULT NULL,
    pago_em DATETIME NULL DEFAULT NULL,
    concluido_em DATETIME NULL DEFAULT NULL,
    cancelado_em DATETIME NULL DEFAULT NULL,
    motivo_cancelamento VARCHAR(200) NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pedidos_numero (id_restaurante, numero),
    UNIQUE KEY uq_pedidos_idempotencia (id_restaurante, chave_idempotencia),
    UNIQUE KEY uq_pedidos_id_restaurante (id, id_restaurante),
    UNIQUE KEY uq_pedidos_pix (pix_cobranca_id),
    KEY idx_pedidos_painel (id_restaurante, status, criado_em),
    KEY idx_pedidos_cliente (id_cliente, criado_em),
    CONSTRAINT fk_pedidos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id),
    CONSTRAINT fk_pedidos_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    CONSTRAINT chk_pedidos_numero CHECK (numero >= 1),
    CONSTRAINT chk_pedidos_valores CHECK (
        subtotal >= 0 AND taxa_entrega >= 0 AND desconto >= 0 AND total >= 0
        AND total = subtotal + taxa_entrega - desconto
    ),
    CONSTRAINT chk_pedidos_troco CHECK (troco_para IS NULL OR (forma_pagamento = 'dinheiro' AND troco_para >= total)),
    CONSTRAINT chk_pedidos_entrega CHECK (
        tipo <> 'entrega' OR (entrega_logradouro IS NOT NULL AND entrega_numero IS NOT NULL AND entrega_bairro IS NOT NULL AND entrega_cidade IS NOT NULL)
    ),
    CONSTRAINT chk_pedidos_whatsapp CHECK (cliente_whatsapp IS NULL OR (cliente_whatsapp COLLATE utf8mb4_bin) REGEXP '^[1-9][0-9]{9,10}$'),
    CONSTRAINT chk_pedidos_cep CHECK (entrega_cep IS NULL OR (entrega_cep COLLATE utf8mb4_bin) REGEXP '^[0-9]{8}$'),
    CONSTRAINT chk_pedidos_coordenadas CHECK (
        (entrega_latitude IS NULL AND entrega_longitude IS NULL)
        OR (entrega_latitude BETWEEN -90 AND 90 AND entrega_longitude BETWEEN -180 AND 180)
    ),
    CONSTRAINT chk_pedidos_idempotencia CHECK ((chave_idempotencia COLLATE utf8mb4_bin) REGEXP '^[a-f0-9]{32}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Itens do pedido. Nome e preco COPIADOS do produto no momento do pedido (o cardapio pode mudar depois).
    // FKs compostas com id_restaurante: um item nunca aponta para pedido ou produto de outro restaurante.
    // id_produto NULL = item avulso (ex.: digitado no caixa). Produto com pedidos nao e apagado: e arquivado.
    $db->exec(<<<'SQL'
CREATE TABLE pedido_itens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_pedido BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NULL DEFAULT NULL,
    nome VARCHAR(180) NOT NULL,
    preco_unitario DECIMAL(10,2) NOT NULL,
    quantidade SMALLINT UNSIGNED NOT NULL,
    observacao VARCHAR(140) NULL DEFAULT NULL,
    total DECIMAL(10,2) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pedido_itens_pedido (id_pedido, id_restaurante),
    KEY idx_pedido_itens_produto (id_produto, id_restaurante),
    CONSTRAINT fk_pedido_itens_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos (id, id_restaurante),
    CONSTRAINT fk_pedido_itens_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante),
    CONSTRAINT chk_pedido_itens_quantidade CHECK (quantidade BETWEEN 1 AND 99),
    CONSTRAINT chk_pedido_itens_valores CHECK (preco_unitario >= 0 AND total = preco_unitario * quantidade),
    CONSTRAINT chk_pedido_itens_nome CHECK (CHAR_LENGTH(TRIM(nome)) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Linha do tempo do pedido: cada mudanca de status, quem fez (equipe ou cliente) e quando.
    $db->exec(<<<'SQL'
CREATE TABLE pedido_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_pedido BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    status ENUM('aguardando_pagamento', 'recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido', 'cancelado') NOT NULL,
    descricao VARCHAR(200) NOT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    id_cliente BIGINT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pedido_eventos_pedido (id_pedido, id_restaurante, id),
    CONSTRAINT fk_pedido_eventos_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos (id, id_restaurante),
    CONSTRAINT fk_pedido_eventos_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    CONSTRAINT fk_pedido_eventos_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Cadastro do cliente na conta Asaas DE CADA RESTAURANTE (para gerar a cobranca Pix). O Asaas aceita
    // clientes duplicados, entao guardamos o id para reutilizar. Um por restaurante, cliente e ambiente.
    $db->exec(<<<'SQL'
CREATE TABLE asaas_clientes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_cliente BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('producao', 'sandbox') NOT NULL,
    id_externo VARCHAR(60) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asaas_clientes (id_restaurante, id_cliente, ambiente),
    CONSTRAINT fk_asaas_clientes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_asaas_clientes_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
