<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // PEDIDOS DO iFOOD no mesmo fluxo do delivery (caixa, cozinha, estoque, financeiro).
    // - ifood_id: id do pedido no iFood (UNICO por restaurante: o mesmo pedido nunca entra duas vezes).
    // - ifood_display_id: codigo curto que o iFood mostra ao cliente e ao entregador.
    // - ifood_entregue_por: quem leva (entregador do iFood ou a equipe da loja).
    // - ifood_codigo_coleta: codigo que a loja confere com o entregador do iFood na retirada.
    // - ifood_dados: JSON sem segredo com pagamento, cupons, entregador, etapa da entrega e avisos.
    // - forma_pagamento 'ifood' = pago pelo app do iFood (o dinheiro vem no repasse do iFood).
    $db->exec(<<<'SQL'
ALTER TABLE pedidos
    MODIFY forma_pagamento ENUM('pix_online', 'pix_entrega', 'debito', 'credito', 'dinheiro', 'misto', 'ifood') NULL DEFAULT NULL,
    ADD COLUMN ifood_id VARCHAR(64) NULL DEFAULT NULL AFTER chave_idempotencia,
    ADD COLUMN ifood_display_id VARCHAR(20) NULL DEFAULT NULL AFTER ifood_id,
    ADD COLUMN ifood_entregue_por ENUM('ifood', 'loja') NULL DEFAULT NULL AFTER ifood_display_id,
    ADD COLUMN ifood_codigo_coleta VARCHAR(20) NULL DEFAULT NULL AFTER ifood_entregue_por,
    ADD COLUMN ifood_dados MEDIUMTEXT NULL DEFAULT NULL AFTER ifood_codigo_coleta,
    ADD UNIQUE KEY uq_pedidos_ifood (id_restaurante, ifood_id),
    ADD CONSTRAINT chk_pedidos_ifood CHECK (ifood_id IS NULL OR origem = 'ifood')
SQL);

    // Pagamento registrado ao concluir um pedido pago pelo app do iFood.
    $db->exec("ALTER TABLE pedido_pagamentos MODIFY forma ENUM('dinheiro', 'debito', 'credito', 'pix', 'pix_online', 'ifood') NOT NULL");

    // Itens do iFood chegam com complementos (ex.: borda, adicionais, meio a meio): cabem na observacao do item.
    $db->exec('ALTER TABLE pedido_itens MODIFY observacao VARCHAR(400) NULL DEFAULT NULL');

    // Avisos do iFood ficam guardados antes de serem tratados: se falhar, tenta de novo (ate 10 vezes).
    $db->exec(<<<'SQL'
ALTER TABLE integracao_eventos
    ADD COLUMN tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER processado_em,
    ADD COLUMN erro VARCHAR(255) NULL DEFAULT NULL AFTER tentativas,
    ADD KEY idx_integracao_eventos_pendentes (provedor, processado_em, id)
SQL);

    // CARDAPIO DO iFOOD ligado aos produtos do restaurante: o pedido do iFood baixa o estoque certo e a
    // disponibilidade do produto (estoque, pausa) e enviada ao iFood. enviado_* = ultimo valor aceito pelo iFood
    // (so envia de novo quando muda).
    $db->exec(<<<'SQL'
CREATE TABLE ifood_itens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ifood_item_id VARCHAR(64) NOT NULL,
    ifood_produto_id VARCHAR(64) NULL DEFAULT NULL,
    nome VARCHAR(180) NOT NULL,
    codigo_externo VARCHAR(80) NULL DEFAULT NULL,
    categoria VARCHAR(120) NULL DEFAULT NULL,
    preco DECIMAL(10,2) NULL DEFAULT NULL,
    status_ifood VARCHAR(20) NULL DEFAULT NULL,
    id_produto BIGINT UNSIGNED NULL DEFAULT NULL,
    vinculo ENUM('automatico', 'manual') NULL DEFAULT NULL,
    enviado_status VARCHAR(20) NULL DEFAULT NULL,
    enviado_estoque INT UNSIGNED NULL DEFAULT NULL,
    sincronizado_em DATETIME NULL DEFAULT NULL,
    ultimo_erro VARCHAR(255) NULL DEFAULT NULL,
    visto_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ifood_itens_item (id_restaurante, ifood_item_id),
    KEY idx_ifood_itens_produto (id_produto, id_restaurante),
    KEY idx_ifood_itens_codigo (id_restaurante, codigo_externo),
    CONSTRAINT fk_ifood_itens_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_ifood_itens_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante),
    CONSTRAINT chk_ifood_itens_vinculo CHECK ((id_produto IS NULL AND vinculo IS NULL) OR (id_produto IS NOT NULL AND vinculo IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // FILA de acoes para o iFood (aceitar, pronto, despachar, cancelar): gravada junto com a mudanca no pedido,
    // enviada logo depois e repetida pela rotina se o iFood nao responder.
    $db->exec(<<<'SQL'
CREATE TABLE ifood_acoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_pedido BIGINT UNSIGNED NOT NULL,
    acao VARCHAR(30) NOT NULL,
    dados VARCHAR(500) NULL DEFAULT NULL,
    status ENUM('pendente', 'feito', 'falhou') NOT NULL DEFAULT 'pendente',
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro VARCHAR(255) NULL DEFAULT NULL,
    proxima_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL,
    feito_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_ifood_acoes_fila (status, proxima_em, id),
    KEY idx_ifood_acoes_pedido (id_pedido, id_restaurante),
    CONSTRAINT fk_ifood_acoes_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos (id, id_restaurante),
    CONSTRAINT chk_ifood_acoes_acao CHECK (acao IN ('confirmar', 'preparar', 'pronto', 'despachar', 'cancelar'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
