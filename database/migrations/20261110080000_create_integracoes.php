<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Uma linha por restaurante e provedor (iFood, Asaas, WhatsApp/WPPConnect).
    // credenciais: JSON CIFRADO (v1: AES-256-GCM, chave fora do banco) com chaves/tokens.
    // dados: JSON sem segredos (nome da conta, loja escolhida, sessao, numero conectado).
    // webhook_id: identificador aleatorio usado na URL publica do webhook (/webhooks/asaas/{id}).
    $db->exec(<<<'SQL'
CREATE TABLE integracoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    provedor ENUM('ifood', 'asaas', 'whatsapp') NOT NULL,
    status ENUM('desconectado', 'pendente', 'conectado', 'erro') NOT NULL DEFAULT 'desconectado',
    ambiente ENUM('producao', 'sandbox') NULL DEFAULT NULL,
    credenciais TEXT NULL DEFAULT NULL,
    dados TEXT NULL DEFAULT NULL,
    webhook_id CHAR(32) NULL DEFAULT NULL,
    ultimo_erro VARCHAR(255) NULL DEFAULT NULL,
    conectado_em DATETIME NULL DEFAULT NULL,
    verificado_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracoes_restaurante_provedor (id_restaurante, provedor),
    UNIQUE KEY uq_integracoes_webhook (webhook_id),
    CONSTRAINT fk_integracoes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_integracoes_webhook CHECK (webhook_id IS NULL OR (webhook_id COLLATE utf8mb4_bin) REGEXP '^[a-f0-9]{32}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Avisos recebidos dos provedores (webhooks). id_externo evita processar o mesmo aviso duas vezes.
    $db->exec(<<<'SQL'
CREATE TABLE integracao_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    provedor ENUM('ifood', 'asaas', 'whatsapp') NOT NULL,
    evento VARCHAR(80) NOT NULL,
    id_externo VARCHAR(120) NULL DEFAULT NULL,
    payload MEDIUMTEXT NOT NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integracao_eventos_externo (provedor, id_externo),
    KEY idx_integracao_eventos_restaurante (id_restaurante, provedor, recebido_em),
    CONSTRAINT fk_integracao_eventos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
