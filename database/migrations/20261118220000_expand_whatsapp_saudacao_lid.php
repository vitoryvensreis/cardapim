<?php

declare(strict_types=1);

/**
 * O webhook pode receber o identificador de contato @lid (nao e telefone).
 * Ampliar o campo existente preserva todos os registros e a UNIQUE diaria.
 * A camada de aplicacao so comeca a usar LID depois desta migration.
 */
return static function (PDO $db): void {
    $db->exec("ALTER TABLE whatsapp_saudacao_diaria MODIFY COLUMN telefone VARCHAR(48) NOT NULL");
};
