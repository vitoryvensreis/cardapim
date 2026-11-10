<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // CPF armazenado somente com 11 digitos (sem pontuacao). NULL para contas antigas
    // e para o adm_master global. Dado pessoal: exibir mascarado e validar no backend.
    $db->exec(<<<'SQL'
ALTER TABLE `user`
    ADD COLUMN cpf CHAR(11) NULL DEFAULT NULL AFTER sobrenome,
    ADD UNIQUE KEY uq_user_cpf (cpf),
    ADD CONSTRAINT chk_user_cpf CHECK (cpf IS NULL OR cpf REGEXP '^[0-9]{11}$')
SQL);
};
