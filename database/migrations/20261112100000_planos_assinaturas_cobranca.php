<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Planos: periodo "vitalicio" (sempre gratuito, ex.: parceiro) e plano privado com link de convite.
    $db->exec(<<<'SQL'
ALTER TABLE planos
    MODIFY periodo ENUM('mensal', 'semestral', 'anual', 'vitalicio') NOT NULL,
    ADD COLUMN codigo_convite CHAR(24) NULL DEFAULT NULL AFTER visibilidade,
    ADD UNIQUE KEY uq_planos_convite (codigo_convite),
    ADD CONSTRAINT chk_planos_vitalicio CHECK (periodo <> 'vitalicio' OR valor = 0)
SQL);

    // Restaurante com fatura atrasada alem da tolerancia: so o gerente entra, e so para pagar.
    $db->exec(<<<'SQL'
ALTER TABLE restaurantes
    MODIFY status_rest ENUM('pendente', 'ativo', 'inadimplente', 'suspenso', 'inativo') NOT NULL DEFAULT 'pendente'
SQL);

    // Assinatura ligada ao Asaas da plataforma. aguardando = esperando o 1o pagamento; vence_em NULL = vitalicia
    // (ou ainda sem pagamento). O valor e o periodo continuam copiados do plano.
    $db->exec(<<<'SQL'
ALTER TABLE assinaturas
    MODIFY periodo ENUM('mensal', 'semestral', 'anual', 'vitalicio') NOT NULL,
    MODIFY status ENUM('aguardando', 'ativa', 'cancelada', 'encerrada') NOT NULL DEFAULT 'ativa',
    MODIFY vence_em DATE NULL DEFAULT NULL,
    ADD COLUMN ambiente ENUM('sandbox', 'producao') NULL DEFAULT NULL AFTER encerrada_em,
    ADD COLUMN asaas_cliente_id VARCHAR(60) NULL DEFAULT NULL AFTER ambiente,
    ADD COLUMN asaas_assinatura_id VARCHAR(60) NULL DEFAULT NULL AFTER asaas_cliente_id,
    ADD COLUMN cobranca_erro VARCHAR(255) NULL DEFAULT NULL AFTER asaas_assinatura_id,
    ADD KEY idx_assinaturas_asaas (asaas_assinatura_id)
SQL);

    // Faturas das assinaturas (cada cobranca gerada pelo Asaas). Atualizadas pelos avisos (webhook).
    $db->exec(<<<'SQL'
CREATE TABLE assinatura_faturas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_assinatura BIGINT UNSIGNED NULL DEFAULT NULL,
    ambiente ENUM('sandbox', 'producao') NOT NULL,
    asaas_id VARCHAR(60) NOT NULL,
    valor DECIMAL(10, 2) NOT NULL,
    vencimento DATE NOT NULL,
    status ENUM('pendente', 'paga', 'vencida', 'cancelada', 'estornada') NOT NULL DEFAULT 'pendente',
    forma VARCHAR(20) NULL DEFAULT NULL,
    link_fatura VARCHAR(500) NULL DEFAULT NULL,
    link_boleto VARCHAR(500) NULL DEFAULT NULL,
    pago_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_faturas_asaas (ambiente, asaas_id),
    KEY idx_faturas_restaurante (id_restaurante, vencimento),
    KEY idx_faturas_status (status, vencimento),
    CONSTRAINT fk_faturas_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_faturas_assinatura FOREIGN KEY (id_assinatura) REFERENCES assinaturas (id) ON DELETE SET NULL,
    CONSTRAINT chk_faturas_valor CHECK (valor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
