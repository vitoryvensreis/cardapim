<?php

declare(strict_types=1);

/**
 * Fila de impressao pelo Deliveryzim Agent (Windows).
 * - O caixa ou o garcom pede a impressao no navegador; o servidor monta o cupom (blocos de texto) e grava aqui.
 * - O primeiro Agent do restaurante que consultar reserva o pedido de impressao (id_dispositivo + entregue_em)
 *   e devolve o resultado (ok/erro). Pedido nao reservado em 2 minutos expira (nunca imprime atrasado de surpresa).
 * - ate_item: maior item da comanda incluido no cupom. "So os itens novos" do garcom imprime o que veio depois.
 * - desktop_agent_devices.impressao_em: ultima consulta de um Agent que sabe imprimir (versao 0.7.0 ou mais nova).
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE impressoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_pedido BIGINT UNSIGNED NULL DEFAULT NULL,
    tipo VARCHAR(20) NOT NULL,
    titulo VARCHAR(80) NOT NULL,
    conteudo MEDIUMTEXT NOT NULL,
    ate_item BIGINT UNSIGNED NULL DEFAULT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    id_dispositivo BIGINT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    entregue_em DATETIME NULL DEFAULT NULL,
    resultado VARCHAR(10) NULL DEFAULT NULL,
    erro VARCHAR(200) NULL DEFAULT NULL,
    resultado_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_impressoes_fila (id_restaurante, entregue_em, criado_em),
    KEY idx_impressoes_pedido (id_restaurante, id_pedido, id),
    CONSTRAINT chk_impressoes_tipo CHECK (tipo IN ('conta', 'pre_conta', 'itens_novos', 'pedido', 'fechamento')),
    CONSTRAINT chk_impressoes_resultado CHECK (resultado IS NULL OR resultado IN ('ok', 'erro')),
    CONSTRAINT fk_impressoes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT fk_impressoes_usuario FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE SET NULL,
    CONSTRAINT fk_impressoes_dispositivo FOREIGN KEY (id_dispositivo) REFERENCES desktop_agent_devices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec('ALTER TABLE desktop_agent_devices ADD COLUMN impressao_em DATETIME NULL DEFAULT NULL AFTER ultimo_acesso_em');
};
