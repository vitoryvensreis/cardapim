<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Historico de assinaturas por restaurante. Valor e periodo sao copiados do plano (o preco
    // do plano pode mudar depois sem alterar o que foi contratado).
    // status: ativa | cancelada (continua valendo ate vence_em) | encerrada (substituida ou vencida).
    $db->exec(<<<'SQL'
CREATE TABLE assinaturas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    plano_id BIGINT UNSIGNED NOT NULL,
    valor DECIMAL(10, 2) NOT NULL,
    periodo ENUM('mensal', 'semestral', 'anual') NOT NULL,
    status ENUM('ativa', 'cancelada', 'encerrada') NOT NULL DEFAULT 'ativa',
    inicio_em DATE NOT NULL,
    vence_em DATE NOT NULL,
    cancelada_em DATETIME NULL DEFAULT NULL,
    encerrada_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assinaturas_restaurante (id_restaurante, status, id),
    CONSTRAINT fk_assinaturas_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_assinaturas_plano FOREIGN KEY (plano_id) REFERENCES planos (id),
    CONSTRAINT chk_assinaturas_valor CHECK (valor >= 0),
    CONSTRAINT chk_assinaturas_datas CHECK (vence_em > inicio_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Carga inicial: restaurantes que ja tem plano ganham a assinatura atual.
    $db->exec(<<<'SQL'
INSERT INTO assinaturas (id_restaurante, plano_id, valor, periodo, status, inicio_em, vence_em)
SELECT r.id, p.id, p.valor, p.periodo, 'ativa',
       COALESCE(r.ultimo_pagamento, CURRENT_DATE),
       CASE p.periodo
           WHEN 'mensal' THEN DATE_ADD(COALESCE(r.ultimo_pagamento, CURRENT_DATE), INTERVAL 1 MONTH)
           WHEN 'semestral' THEN DATE_ADD(COALESCE(r.ultimo_pagamento, CURRENT_DATE), INTERVAL 6 MONTH)
           ELSE DATE_ADD(COALESCE(r.ultimo_pagamento, CURRENT_DATE), INTERVAL 1 YEAR)
       END
FROM restaurantes r
JOIN planos p ON p.id = r.plano_id
SQL);
};
