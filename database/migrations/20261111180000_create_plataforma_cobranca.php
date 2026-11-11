<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Regras de cobranca das ASSINATURAS dos restaurantes (Asaas da plataforma). Uma linha so (id = 1).
    // Usadas ao criar a assinatura no Asaas (formas de pagamento, vencimento, multa, juros e desconto) e pelo
    // Cardapim para suspender restaurante com fatura atrasada.
    $db->exec(<<<'SQL'
CREATE TABLE plataforma_cobranca (
    id TINYINT UNSIGNED NOT NULL,
    formas VARCHAR(40) NOT NULL DEFAULT 'pix,boleto',
    vencimento_dias TINYINT UNSIGNED NOT NULL DEFAULT 3,
    teste_dias TINYINT UNSIGNED NOT NULL DEFAULT 0,
    multa_percentual DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    juros_mensal DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    desconto_percentual DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    desconto_dias TINYINT UNSIGNED NOT NULL DEFAULT 0,
    tolerancia_dias TINYINT UNSIGNED NOT NULL DEFAULT 7,
    avisos_asaas TINYINT(1) NOT NULL DEFAULT 1,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    atualizado_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_plataforma_cobranca_um CHECK (id = 1),
    CONSTRAINT chk_plataforma_cobranca_multa CHECK (multa_percentual >= 0 AND multa_percentual <= 2),
    CONSTRAINT chk_plataforma_cobranca_juros CHECK (juros_mensal >= 0 AND juros_mensal <= 10),
    CONSTRAINT chk_plataforma_cobranca_desconto CHECK (desconto_percentual >= 0 AND desconto_percentual <= 50),
    CONSTRAINT chk_plataforma_cobranca_dias CHECK (vencimento_dias <= 30 AND teste_dias <= 60 AND desconto_dias <= 30 AND tolerancia_dias <= 60),
    CONSTRAINT fk_plataforma_cobranca_user FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec('INSERT INTO plataforma_cobranca (id) VALUES (1)');
};
