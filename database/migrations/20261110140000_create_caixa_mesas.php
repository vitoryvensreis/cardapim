<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // MESAS do salao. A mesa funciona como "cliente" da comanda: o pedido da mesa (tipo 'mesa') aponta para ela.
    // Mesa com historico nao e apagada: fica excluida_em preenchido e numero NULL (libera o numero para outra mesa).
    $db->exec(<<<'SQL'
CREATE TABLE mesas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    numero SMALLINT UNSIGNED NULL DEFAULT NULL,
    apelido VARCHAR(40) NULL DEFAULT NULL,
    lugares TINYINT UNSIGNED NULL DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    excluida_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mesas_numero (id_restaurante, numero),
    UNIQUE KEY uq_mesas_id_restaurante (id, id_restaurante),
    CONSTRAINT fk_mesas_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_mesas_numero CHECK ((numero IS NOT NULL AND numero BETWEEN 1 AND 9999) OR (numero IS NULL AND excluida_em IS NOT NULL)),
    CONSTRAINT chk_mesas_lugares CHECK (lugares IS NULL OR lugares BETWEEN 1 AND 99),
    CONSTRAINT chk_mesas_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // CAIXA (turno): abertura com fundo de troco e fechamento com a contagem do dinheiro (fechamento "cego":
    // a pessoa conta antes de ver o valor esperado). Um caixa aberto por restaurante (coluna gerada + UNIQUE).
    // resumo: totais por forma de pagamento no fechamento (JSON), para o historico nao mudar depois.
    $db->exec(<<<'SQL'
CREATE TABLE caixas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    status ENUM('aberto', 'fechado') NOT NULL DEFAULT 'aberto',
    aberto_em DATETIME NOT NULL,
    id_user_abertura BIGINT UNSIGNED NOT NULL,
    valor_abertura DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fechado_em DATETIME NULL DEFAULT NULL,
    id_user_fechamento BIGINT UNSIGNED NULL DEFAULT NULL,
    dinheiro_esperado DECIMAL(10,2) NULL DEFAULT NULL,
    dinheiro_contado DECIMAL(10,2) NULL DEFAULT NULL,
    diferenca DECIMAL(10,2) NULL DEFAULT NULL,
    resumo TEXT NULL DEFAULT NULL,
    observacao VARCHAR(300) NULL DEFAULT NULL,
    aberto_restaurante BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'aberto' THEN id_restaurante ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_caixas_um_aberto (aberto_restaurante),
    UNIQUE KEY uq_caixas_id_restaurante (id, id_restaurante),
    KEY idx_caixas_historico (id_restaurante, aberto_em),
    CONSTRAINT fk_caixas_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_caixas_user_abertura FOREIGN KEY (id_user_abertura) REFERENCES `user` (id),
    CONSTRAINT fk_caixas_user_fechamento FOREIGN KEY (id_user_fechamento) REFERENCES `user` (id),
    CONSTRAINT chk_caixas_valores CHECK (valor_abertura >= 0 AND (dinheiro_contado IS NULL OR dinheiro_contado >= 0)),
    CONSTRAINT chk_caixas_status CHECK (
        (status = 'aberto' AND fechado_em IS NULL)
        OR (status = 'fechado' AND fechado_em IS NOT NULL AND dinheiro_contado IS NOT NULL AND id_user_fechamento IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // ENTRADAS e SAIDAS de dinheiro da gaveta durante o caixa: reforco de troco, sangria, despesas, fornecedor...
    $db->exec(<<<'SQL'
CREATE TABLE caixa_movimentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_caixa BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    tipo ENUM('entrada', 'saida') NOT NULL,
    categoria VARCHAR(40) NOT NULL,
    descricao VARCHAR(200) NULL DEFAULT NULL,
    valor DECIMAL(10,2) NOT NULL,
    id_user BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_caixa_movimentos_caixa (id_caixa, id_restaurante, id),
    CONSTRAINT fk_caixa_movimentos_caixa FOREIGN KEY (id_caixa, id_restaurante) REFERENCES caixas (id, id_restaurante),
    CONSTRAINT fk_caixa_movimentos_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    CONSTRAINT chk_caixa_movimentos_valor CHECK (valor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Ajustes em PEDIDOS para comanda de mesa/balcao, servico, despacho e caixa:
    // - status 'aberto' = comanda aberta (mesa ou balcao recebendo itens);
    // - forma_pagamento pode ficar vazia enquanto a comanda esta aberta; 'misto' = paga com mais de uma forma;
    // - id_mesa (a mesa e o "cliente"), id_entregador (motoqueiro que levou), id_caixa (caixa em que foi recebido);
    // - taxa de servico (percentual) entra no total.
    $db->exec(<<<'SQL'
ALTER TABLE pedidos
    MODIFY status ENUM('aguardando_pagamento', 'aberto', 'recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido', 'cancelado') NOT NULL,
    MODIFY forma_pagamento ENUM('pix_online', 'pix_entrega', 'debito', 'credito', 'dinheiro', 'misto') NULL DEFAULT NULL,
    ADD COLUMN id_mesa BIGINT UNSIGNED NULL DEFAULT NULL AFTER id_user,
    ADD COLUMN id_entregador BIGINT UNSIGNED NULL DEFAULT NULL AFTER id_mesa,
    ADD COLUMN id_caixa BIGINT UNSIGNED NULL DEFAULT NULL AFTER id_entregador,
    ADD COLUMN servico_percentual DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER taxa_entrega,
    ADD COLUMN taxa_servico DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER servico_percentual,
    ADD COLUMN motivo_desconto VARCHAR(140) NULL DEFAULT NULL AFTER desconto,
    ADD COLUMN pessoas TINYINT UNSIGNED NULL DEFAULT NULL AFTER motivo_desconto,
    ADD COLUMN despachado_em DATETIME NULL DEFAULT NULL AFTER pago_em
SQL);
    $db->exec('ALTER TABLE pedidos DROP CHECK chk_pedidos_valores');
    $db->exec(<<<'SQL'
ALTER TABLE pedidos
    ADD COLUMN mesa_aberta BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'aberto' THEN id_mesa ELSE NULL END) STORED,
    ADD UNIQUE KEY uq_pedidos_mesa_aberta (mesa_aberta),
    ADD KEY idx_pedidos_mesa (id_mesa, id_restaurante),
    ADD KEY idx_pedidos_caixa (id_caixa, id_restaurante),
    ADD CONSTRAINT fk_pedidos_mesa FOREIGN KEY (id_mesa, id_restaurante) REFERENCES mesas (id, id_restaurante),
    ADD CONSTRAINT fk_pedidos_entregador FOREIGN KEY (id_entregador) REFERENCES `user` (id),
    ADD CONSTRAINT fk_pedidos_caixa FOREIGN KEY (id_caixa, id_restaurante) REFERENCES caixas (id, id_restaurante),
    ADD CONSTRAINT chk_pedidos_valores CHECK (
        subtotal >= 0 AND taxa_entrega >= 0 AND taxa_servico >= 0 AND desconto >= 0 AND total >= 0
        AND total = subtotal + taxa_entrega + taxa_servico - desconto
    ),
    ADD CONSTRAINT chk_pedidos_servico CHECK (servico_percentual BETWEEN 0 AND 20),
    ADD CONSTRAINT chk_pedidos_mesa CHECK (tipo <> 'mesa' OR id_mesa IS NOT NULL),
    ADD CONSTRAINT chk_pedidos_pessoas CHECK (pessoas IS NULL OR pessoas BETWEEN 1 AND 50),
    ADD CONSTRAINT chk_pedidos_forma CHECK (status IN ('aberto', 'cancelado') OR forma_pagamento IS NOT NULL)
SQL);

    $db->exec(<<<'SQL'
ALTER TABLE pedido_eventos
    MODIFY status ENUM('aguardando_pagamento', 'aberto', 'recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido', 'cancelado') NOT NULL
SQL);

    // Itens da comanda sao lancados aos poucos: quem lancou, o que baixou do estoque (para devolver se o item for
    // cancelado) e o cancelamento do item com motivo. Item cancelado nao entra no total.
    $db->exec(<<<'SQL'
ALTER TABLE pedido_itens
    ADD COLUMN id_user BIGINT UNSIGNED NULL DEFAULT NULL AFTER total,
    ADD COLUMN consumo_estoque TEXT NULL DEFAULT NULL AFTER id_user,
    ADD COLUMN cancelado_em DATETIME NULL DEFAULT NULL AFTER consumo_estoque,
    ADD COLUMN motivo_cancelamento VARCHAR(140) NULL DEFAULT NULL AFTER cancelado_em,
    ADD COLUMN id_user_cancelou BIGINT UNSIGNED NULL DEFAULT NULL AFTER motivo_cancelamento,
    ADD CONSTRAINT fk_pedido_itens_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    ADD CONSTRAINT fk_pedido_itens_user_cancelou FOREIGN KEY (id_user_cancelou) REFERENCES `user` (id),
    ADD CONSTRAINT chk_pedido_itens_cancelamento CHECK (cancelado_em IS NULL OR motivo_cancelamento IS NOT NULL)
SQL);

    // PAGAMENTOS recebidos no caixa (uma conta pode ser paga com varias formas: parte no cartao, parte no dinheiro).
    // valor = quanto abateu da conta; troco = o que voltou ao cliente (so dinheiro). Sempre ligado ao caixa aberto.
    $db->exec(<<<'SQL'
CREATE TABLE pedido_pagamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_pedido BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_caixa BIGINT UNSIGNED NULL DEFAULT NULL,
    forma ENUM('dinheiro', 'debito', 'credito', 'pix', 'pix_online') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    troco DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pedido_pagamentos_pedido (id_pedido, id_restaurante),
    KEY idx_pedido_pagamentos_caixa (id_caixa, id_restaurante),
    CONSTRAINT fk_pedido_pagamentos_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos (id, id_restaurante),
    CONSTRAINT fk_pedido_pagamentos_caixa FOREIGN KEY (id_caixa, id_restaurante) REFERENCES caixas (id, id_restaurante),
    CONSTRAINT fk_pedido_pagamentos_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    CONSTRAINT chk_pedido_pagamentos_valores CHECK (valor > 0 AND troco >= 0 AND (troco = 0 OR forma = 'dinheiro'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
