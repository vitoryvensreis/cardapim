<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Horario de funcionamento: "sempre aberto" ou turnos por dia da semana (0 = domingo ... 6 = sabado).
    // Ate 2 turnos por dia (ex.: almoco e jantar). fecha < abre = fecha no dia seguinte (ex.: 18:00 as 02:00).
    $db->exec(<<<'SQL'
ALTER TABLE restaurantes
    ADD COLUMN sempre_aberto TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_path,
    ADD CONSTRAINT chk_restaurantes_sempre_aberto CHECK (sempre_aberto IN (0, 1))
SQL);
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_horarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL,
    turno TINYINT UNSIGNED NOT NULL DEFAULT 1,
    abre TIME NOT NULL,
    fecha TIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_horarios_dia_turno (id_restaurante, dia_semana, turno),
    CONSTRAINT fk_horarios_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_horarios_dia CHECK (dia_semana BETWEEN 0 AND 6),
    CONSTRAINT chk_horarios_turno CHECK (turno IN (1, 2)),
    CONSTRAINT chk_horarios_intervalo CHECK (abre <> fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
