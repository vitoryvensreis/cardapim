<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Pix com QR Code na mesa (painel do garcom): uma cobranca na conta Asaas DO RESTAURANTE para o que falta
    // pagar da comanda. Quando o Asaas confirma (aviso ou consulta), vira um pagamento "Pix pelo app" no caixa.
    $db->exec(<<<'SQL'
CREATE TABLE comanda_pix (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_pedido BIGINT UNSIGNED NOT NULL,
    id_caixa BIGINT UNSIGNED NOT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    cobranca_id VARCHAR(60) NOT NULL,
    ambiente ENUM('producao', 'sandbox') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    copia_cola VARCHAR(1000) NOT NULL,
    status ENUM('pendente', 'pago', 'cancelado') NOT NULL DEFAULT 'pendente',
    criado_em DATETIME NOT NULL,
    expira_em DATETIME NOT NULL,
    pago_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comanda_pix_cobranca (cobranca_id),
    KEY idx_comanda_pix_pedido (id_restaurante, id_pedido, status),
    CONSTRAINT fk_comanda_pix_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT fk_comanda_pix_caixa FOREIGN KEY (id_caixa, id_restaurante) REFERENCES caixas (id, id_restaurante),
    CONSTRAINT fk_comanda_pix_user FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT chk_comanda_pix_valor CHECK (valor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Cadastro "Clientes do salao" na conta Asaas de cada restaurante (o Asaas exige um pagador na cobranca;
    // na mesa nao pedimos CPF ao cliente, usamos o CNPJ do proprio restaurante). Um por restaurante e ambiente.
    $db->exec(<<<'SQL'
CREATE TABLE asaas_clientes_salao (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ambiente ENUM('producao', 'sandbox') NOT NULL,
    id_externo VARCHAR(60) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante, ambiente),
    CONSTRAINT fk_asaas_salao_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
