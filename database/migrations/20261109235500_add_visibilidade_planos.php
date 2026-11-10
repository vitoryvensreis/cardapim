<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
ALTER TABLE planos
    ADD COLUMN visibilidade TINYINT(1) NOT NULL DEFAULT 1 AFTER ativo,
    ADD CONSTRAINT chk_planos_visibilidade CHECK (visibilidade IN (0, 1))
SQL);
};
