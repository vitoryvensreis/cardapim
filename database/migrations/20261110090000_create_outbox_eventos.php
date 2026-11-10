<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Transactional Outbox do tempo real: todo evento e GRAVADO aqui antes de ser transmitido.
    // O servidor WebSocket (bin/tempo-real.php) le as linhas novas e entrega a quem esta conectado.
    // canal: area que recebe (pedidos, cozinha, estoque, integracoes...); tipo: nome do evento.
    // dados: JSON pequeno, SEM segredos nem dados pessoais completos (a tela busca o resto pela API).
    // id_restaurante NULL = evento da plataforma (reservado para o painel do adm_master).
    // publicado_em: quando o servidor transmitiu. Linhas antigas sao apagadas pelo proprio servidor (7 dias).
    $db->exec(<<<'SQL'
CREATE TABLE outbox_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NULL DEFAULT NULL,
    canal VARCHAR(30) NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    dados TEXT NOT NULL,
    criado_em DATETIME NOT NULL,
    publicado_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_outbox_restaurante (id_restaurante, id),
    KEY idx_outbox_criado (criado_em),
    CONSTRAINT fk_outbox_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT chk_outbox_canal CHECK ((canal COLLATE utf8mb4_bin) REGEXP '^[a-z]{2,30}$'),
    CONSTRAINT chk_outbox_tipo CHECK ((tipo COLLATE utf8mb4_bin) REGEXP '^[a-z]+([._][a-z]+){1,6}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
