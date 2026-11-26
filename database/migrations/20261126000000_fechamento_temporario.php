<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $q = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes' AND COLUMN_NAME = 'fechamentos_temporarios'");
    if ((int) $q->fetchColumn() === 0) {
        $db->exec('ALTER TABLE restaurantes ADD COLUMN fechamentos_temporarios JSON NULL');
    }
};
