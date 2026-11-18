<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Opcao desativada por padrao: nenhuma loja passa a responder sem ativar.
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_whatsapp_saudacao (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    mensagem VARCHAR(1500) NOT NULL,
    atualizado_em DATETIME NOT NULL,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_saudacao_config_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT chk_saudacao_ativo CHECK (ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Regra diaria e duravel: a mesma loja/contato/dia nao recebe duas saudacoes.
    // Envio assincrono com reserva, retry e auditabilidade. Retencao definida no processador.
    $db->exec(<<<'SQL'
CREATE TABLE whatsapp_saudacao_diaria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    telefone VARCHAR(15) NOT NULL,
    dia_local DATE NOT NULL,
    mensagem VARCHAR(1800) NOT NULL,
    status ENUM('pendente','enviando','enviado','falhou') NOT NULL DEFAULT 'pendente',
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL,
    enviado_em DATETIME NULL,
    ultimo_erro VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_saudacao_restaurante_telefone_dia (id_restaurante, telefone, dia_local),
    KEY idx_saudacao_fila (status, proxima_tentativa_em),
    CONSTRAINT fk_saudacao_envio_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
