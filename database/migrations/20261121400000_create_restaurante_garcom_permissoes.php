<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // O que o garcom pode fazer na comanda depois que o item ja foi lancado. Decidido pelo gerente
    // (aba Entrega e pagamento, card "Perfil garçom"). Uma linha por restaurante; sem linha = tudo bloqueado.
    // Gerente e adm_master nao passam por esta regra (sempre podem). O modo caixa tambem nao.
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_garcom_permissoes (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    remover_itens TINYINT(1) NOT NULL DEFAULT 0,
    cancelar_comanda TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_garcom_permissoes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_garcom_permissoes_flags CHECK (remover_itens IN (0, 1) AND cancelar_comanda IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
