<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // log_user.id_user NULL continua permitido para eventos de sistema.
    // ON DELETE RESTRICT preserva historico: desativar usuario, nao apagar.
    $db->exec(<<<'SQL'
ALTER TABLE log_user
    ADD CONSTRAINT fk_log_user_usuario
    FOREIGN KEY (id_user) REFERENCES `user` (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
SQL);
};
