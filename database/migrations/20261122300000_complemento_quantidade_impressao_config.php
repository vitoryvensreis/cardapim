<?php

declare(strict_types=1);

/**
 * - complemento_grupos.repetir: o cliente escolhe a MESMA opcao mais de uma vez (ex.: 3 ovos), com - e +.
 *   0 = cada opcao uma vez so (como sempre foi). Nao muda combos (que ja tem quantidade) nem o iFood.
 * - complementos.qtd_max: limite de vezes daquela opcao no grupo que repete (NULL = livre, ate o maximo do grupo).
 * - restaurante_impressao_config.cancelamento: imprimir (1) ou nao (0) o aviso de item/comanda cancelado.
 *   Sem linha = ligado (como ja funciona). Tabela criada por ultimo (o codigo so usa as novidades quando ela existe).
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE complemento_grupos ADD COLUMN repetir TINYINT(1) NOT NULL DEFAULT 0');
    $db->exec('ALTER TABLE complementos ADD COLUMN qtd_max TINYINT UNSIGNED NULL DEFAULT NULL,
        ADD CONSTRAINT chk_complementos_qtd_max CHECK (qtd_max IS NULL OR qtd_max BETWEEN 1 AND 30)');
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_impressao_config (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    cancelamento TINYINT(1) NOT NULL DEFAULT 1,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_impressao_config_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT chk_impressao_config_cancelamento CHECK (cancelamento IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
