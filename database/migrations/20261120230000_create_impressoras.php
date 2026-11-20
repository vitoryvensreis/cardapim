<?php

declare(strict_types=1);

/**
 * Varias impressoras por restaurante (Deliveryzim Agent 0.8.0+).
 * - impressoras: cada impressora cadastrada num Agent (apelido "Balcao", "Cozinha"...). chave = identificador criado no
 *   proprio Agent; o servidor nunca recebe o nome da impressora do Windows. ativo=0 quando o Agent tirou da lista.
 * - impressao_pontos: o gerente escolhe em quais impressoras cada ponto do sistema imprime e qual e a padrao
 *   (caixa_conta, caixa_delivery, caixa_fechamento, garcom, delivery_novo).
 * - impressoes.id_impressora: impressora escolhida para o cupom (NULL = qualquer Agent, na impressora principal).
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE impressoras (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_dispositivo BIGINT UNSIGNED NOT NULL,
    chave VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    apelido VARCHAR(40) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_impressoras_chave (id_dispositivo, chave),
    KEY idx_impressoras_restaurante (id_restaurante, ativo),
    CONSTRAINT chk_impressoras_ativo CHECK (ativo IN (0, 1)),
    CONSTRAINT fk_impressoras_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT fk_impressoras_dispositivo FOREIGN KEY (id_dispositivo, id_restaurante)
        REFERENCES desktop_agent_devices (id, id_restaurante) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE impressao_pontos (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ponto VARCHAR(20) NOT NULL,
    id_impressora BIGINT UNSIGNED NOT NULL,
    padrao TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id_restaurante, ponto, id_impressora),
    KEY idx_impressao_pontos_impressora (id_impressora),
    CONSTRAINT chk_impressao_pontos_ponto CHECK (ponto IN ('caixa_conta', 'caixa_delivery', 'caixa_fechamento', 'garcom', 'delivery_novo')),
    CONSTRAINT chk_impressao_pontos_padrao CHECK (padrao IN (0, 1)),
    CONSTRAINT fk_impressao_pontos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT fk_impressao_pontos_impressora FOREIGN KEY (id_impressora) REFERENCES impressoras (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec('ALTER TABLE impressoes ADD COLUMN id_impressora BIGINT UNSIGNED NULL DEFAULT NULL AFTER id_dispositivo,
        ADD KEY idx_impressoes_impressora (id_impressora),
        ADD CONSTRAINT fk_impressoes_impressora FOREIGN KEY (id_impressora) REFERENCES impressoras (id) ON DELETE SET NULL');
};
