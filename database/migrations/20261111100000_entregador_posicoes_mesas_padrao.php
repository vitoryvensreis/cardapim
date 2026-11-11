<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Ultima posicao de cada entregador (so a ultima: nada de historico de onde a pessoa andou - LGPD).
    // Gravada enquanto ele compartilha a localizacao no painel; apagada quando para ou termina as entregas.
    // O cliente so ve enquanto o PEDIDO DELE esta "saiu para entrega" com esse entregador.
    $db->exec(<<<'SQL'
CREATE TABLE entregador_posicoes (
    id_user BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    precisao_m SMALLINT UNSIGNED NULL DEFAULT NULL,
    direcao SMALLINT UNSIGNED NULL DEFAULT NULL,
    velocidade_kmh SMALLINT UNSIGNED NULL DEFAULT NULL,
    atualizado_em DATETIME NOT NULL,
    PRIMARY KEY (id_user),
    KEY idx_entregador_posicoes_restaurante (id_restaurante),
    CONSTRAINT fk_entregador_posicoes_user FOREIGN KEY (id_user) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_entregador_posicoes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT chk_entregador_posicoes_coordenadas CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_entregador_posicoes_direcao CHECK (direcao IS NULL OR direcao < 360)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Todo restaurante comeca com 5 mesas (1 a 5). So para quem ainda nao tem nenhuma mesa cadastrada.
    $db->exec(<<<'SQL'
INSERT INTO mesas (id_restaurante, numero, lugares, ativo, criado_em, atualizado_em)
SELECT r.id, n.numero, 4, 1, NOW(), NOW()
FROM restaurantes r
CROSS JOIN (SELECT 1 AS numero UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) n
WHERE NOT EXISTS (SELECT 1 FROM mesas m WHERE m.id_restaurante = r.id)
SQL);
};
