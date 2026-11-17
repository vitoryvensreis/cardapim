<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // COMPLEMENTOS (adicionais, bordas, molhos...). So tabelas e colunas NOVAS: nada existente muda.
    // Produto sem grupo de complementos continua igual. Grupo e reutilizavel em varios produtos ("Bordas" em todas as pizzas).
    // minimo/maximo: quantas escolhas o cliente faz no grupo (minimo 0 = opcional; minimo >= 1 = obrigatorio).
    $db->exec(<<<'SQL'
CREATE TABLE complemento_grupos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(80) NOT NULL,
    minimo TINYINT UNSIGNED NOT NULL DEFAULT 0,
    maximo TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complemento_grupos_id_restaurante (id, id_restaurante),
    KEY idx_complemento_grupos_restaurante (id_restaurante, ativo, ordem),
    CONSTRAINT fk_complemento_grupos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_complemento_grupos_limites CHECK (maximo BETWEEN 1 AND 30 AND minimo <= maximo),
    CONSTRAINT chk_complemento_grupos_ativo CHECK (ativo IN (0, 1)),
    CONSTRAINT chk_complemento_grupos_nome CHECK (CHAR_LENGTH(TRIM(nome)) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Cada opcao do grupo. Nunca apagar se ja foi vendida (pedidos copiam nome e preco, mas o id ajuda relatorios): arquivar com ativo = 0.
    // disponivel = pausa manual; falta de insumo e calculada pela ficha tecnica (complemento_insumos).
    $db->exec(<<<'SQL'
CREATE TABLE complementos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_grupo BIGINT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    descricao VARCHAR(255) NULL DEFAULT NULL,
    preco DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    codigo VARCHAR(40) NULL DEFAULT NULL,
    imagem_path VARCHAR(500) NULL DEFAULT NULL,
    disponivel TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complementos_id_restaurante (id, id_restaurante),
    UNIQUE KEY uq_complementos_restaurante_codigo (id_restaurante, codigo),
    KEY idx_complementos_grupo (id_grupo, id_restaurante, ativo, ordem),
    CONSTRAINT fk_complementos_grupo FOREIGN KEY (id_grupo, id_restaurante) REFERENCES complemento_grupos (id, id_restaurante),
    CONSTRAINT chk_complementos_preco CHECK (preco >= 0),
    CONSTRAINT chk_complementos_flags CHECK (disponivel IN (0, 1) AND ativo IN (0, 1)),
    CONSTRAINT chk_complementos_nome CHECK (CHAR_LENGTH(TRIM(nome)) >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Ficha tecnica do complemento: quanto de cada insumo vai em 1 unidade do complemento.
    $db->exec(<<<'SQL'
CREATE TABLE complemento_insumos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_complemento BIGINT UNSIGNED NOT NULL,
    id_insumo BIGINT UNSIGNED NOT NULL,
    quantidade DECIMAL(12, 3) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complemento_insumos_item (id_complemento, id_insumo),
    KEY idx_complemento_insumos_insumo (id_insumo, id_restaurante),
    CONSTRAINT fk_complemento_insumos_complemento FOREIGN KEY (id_complemento, id_restaurante) REFERENCES complementos (id, id_restaurante),
    CONSTRAINT fk_complemento_insumos_insumo FOREIGN KEY (id_insumo, id_restaurante) REFERENCES insumos (id, id_restaurante),
    CONSTRAINT chk_complemento_insumos_quantidade CHECK (quantidade > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Quais grupos cada produto usa e em que ordem aparecem.
    $db->exec(<<<'SQL'
CREATE TABLE produto_complemento_grupos (
    id_produto BIGINT UNSIGNED NOT NULL,
    id_grupo BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id_produto, id_grupo),
    KEY idx_produto_complemento_grupos_grupo (id_grupo, id_restaurante),
    KEY idx_produto_complemento_grupos_produto (id_produto, id_restaurante),
    CONSTRAINT fk_pcg_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante),
    CONSTRAINT fk_pcg_grupo FOREIGN KEY (id_grupo, id_restaurante) REFERENCES complemento_grupos (id, id_restaurante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Complementos escolhidos em cada item do pedido: nome, grupo e preco COPIADOS (o cardapio pode mudar depois).
    // quantidade = por 1 unidade do item. pedido_itens.preco_unitario ja inclui os complementos (preco base + soma daqui),
    // entao subtotal, financeiro, relatorios, cupons e fidelidade continuam corretos sem mudanca.
    $db->exec(<<<'SQL'
CREATE TABLE pedido_item_complementos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_pedido_item BIGINT UNSIGNED NOT NULL,
    id_complemento BIGINT UNSIGNED NULL DEFAULT NULL,
    grupo VARCHAR(80) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    preco_unitario DECIMAL(10, 2) NOT NULL,
    quantidade SMALLINT UNSIGNED NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pic_item (id_pedido_item),
    KEY idx_pic_complemento (id_complemento, id_restaurante),
    CONSTRAINT fk_pic_item FOREIGN KEY (id_pedido_item) REFERENCES pedido_itens (id),
    CONSTRAINT fk_pic_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_pic_complemento FOREIGN KEY (id_complemento, id_restaurante) REFERENCES complementos (id, id_restaurante),
    CONSTRAINT chk_pic_quantidade CHECK (quantidade BETWEEN 1 AND 99),
    CONSTRAINT chk_pic_valores CHECK (preco_unitario >= 0 AND total = preco_unitario * quantidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Resumo legivel dos complementos no item ("Borda: Catupiry · Adicionais: 2x Bacon"), mostrado no caixa, cozinha e cliente.
    $db->exec('ALTER TABLE pedido_itens ADD COLUMN complementos_texto VARCHAR(500) NULL DEFAULT NULL AFTER observacao');

    // CARDAPIO PUBLICADO NO iFOOD: o que daqui ja existe no iFood (categoria, item, grupo, complemento), com os ids de la.
    // status/tentativas/proxima_em = fila de publicacao (a rotina bin/ifood.php envia o que estiver pendente, com novas tentativas).
    // enviado_* = ultimo valor aceito pelo iFood: preco e pausa mudam so por PATCH; o resto (nome, foto, grupos) pelo cadastro do item.
    $db->exec(<<<'SQL'
CREATE TABLE ifood_catalogo_vinculos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    tipo ENUM('categoria', 'item', 'grupo', 'complemento') NOT NULL,
    id_local BIGINT UNSIGNED NOT NULL,
    ifood_id VARCHAR(64) NOT NULL,
    ifood_produto_id VARCHAR(64) NULL DEFAULT NULL,
    ifood_imagem VARCHAR(500) NULL DEFAULT NULL,
    imagem_enviada VARCHAR(500) NULL DEFAULT NULL,
    hash_cadastro CHAR(40) NULL DEFAULT NULL,
    enviado_preco DECIMAL(10, 2) NULL DEFAULT NULL,
    enviado_status VARCHAR(20) NULL DEFAULT NULL,
    status ENUM('pendente', 'publicado', 'erro') NOT NULL DEFAULT 'pendente',
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_em DATETIME NULL DEFAULT NULL,
    ultimo_erro VARCHAR(255) NULL DEFAULT NULL,
    publicado_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifood_catalogo_local (id_restaurante, tipo, id_local),
    UNIQUE KEY uq_ifood_catalogo_ifood (id_restaurante, tipo, ifood_id),
    KEY idx_ifood_catalogo_fila (status, proxima_em, id),
    CONSTRAINT fk_ifood_catalogo_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
