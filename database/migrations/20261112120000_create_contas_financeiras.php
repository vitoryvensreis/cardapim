<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // CONTAS A PAGAR e A RECEBER do restaurante (aba Financeiro): aluguel, fornecedor, energia, encomenda a receber...
    // aberta = ainda nao paga/recebida; paga = baixada na data pago_em. Valor sempre positivo (o tipo diz o sentido).
    $db->exec(<<<'SQL'
CREATE TABLE contas_financeiras (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    tipo ENUM('pagar', 'receber') NOT NULL,
    descricao VARCHAR(120) NOT NULL,
    categoria VARCHAR(30) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    vencimento DATE NOT NULL,
    status ENUM('aberta', 'paga') NOT NULL DEFAULT 'aberta',
    pago_em DATE NULL DEFAULT NULL,
    forma VARCHAR(20) NULL DEFAULT NULL,
    observacao VARCHAR(300) NULL DEFAULT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contas_restaurante (id_restaurante, status, vencimento),
    KEY idx_contas_pagas (id_restaurante, pago_em),
    CONSTRAINT fk_contas_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_contas_user FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT chk_contas_valor CHECK (valor > 0 AND valor <= 9999999.99),
    CONSTRAINT chk_contas_status CHECK ((status = 'aberta' AND pago_em IS NULL) OR (status = 'paga' AND pago_em IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
