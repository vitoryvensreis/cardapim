<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Fila dos avisos de pedido pelo WhatsApp DO RESTAURANTE (Transactional Outbox): o aviso e gravado na mesma
    // transacao da mudanca do pedido e enviado depois (fora da resposta), com novas tentativas e sem repetir.
    // O telefone NAO e copiado: vem de pedidos.cliente_whatsapp na hora de enviar.
    $db->exec(<<<'SQL'
CREATE TABLE pedido_avisos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_pedido BIGINT UNSIGNED NOT NULL,
    evento VARCHAR(20) NOT NULL,
    mensagem TEXT NOT NULL,
    status ENUM('pendente', 'enviado', 'falhou', 'ignorado') NOT NULL DEFAULT 'pendente',
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro VARCHAR(255) NULL DEFAULT NULL,
    proxima_tentativa_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL,
    enviado_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pedido_avisos_evento (id_pedido, evento),
    KEY idx_pedido_avisos_fila (id_restaurante, status, proxima_tentativa_em),
    KEY idx_pedido_avisos_recentes (id_restaurante, id),
    CONSTRAINT fk_pedido_avisos_pedido FOREIGN KEY (id_pedido, id_restaurante) REFERENCES pedidos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT chk_pedido_avisos_evento CHECK (evento IN ('recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido', 'cancelado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Quais avisos cada restaurante quer mandar. Sem linha = todos ligados.
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_avisos_whatsapp (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    eventos VARCHAR(120) NOT NULL DEFAULT '',
    atualizado_em DATETIME NOT NULL,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_restaurante_avisos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
