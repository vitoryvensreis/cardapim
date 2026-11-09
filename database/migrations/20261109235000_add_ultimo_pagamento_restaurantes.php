<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
ALTER TABLE restaurantes
    ADD COLUMN ultimo_pagamento DATE NULL DEFAULT NULL AFTER plano_id
SQL);
};
