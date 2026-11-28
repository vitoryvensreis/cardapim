<?php

declare(strict_types=1);

/**
 * Avisos da plataforma para as lojas: pop-up que aparece para o gerente em qualquer tela da gerencia.
 * - avisos_loja: titulo, texto, imagem opcional (1:1, /midia/popups/...), botao com destino (tela da loja ou link
 *   externo), periodo (inicio/fim) e quantas vezes por dia cada gerente ve.
 * - avisos_loja_exibicoes: quantas vezes cada gerente viu no dia e se clicou no botao.
 * avisos_loja_exibicoes.clicou_em e criada por ultimo (Esquema::tem 'avisos_loja').
 */
return static function (PDO $db): void {
    $tabelas = static function () use ($db): array {
        return $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    };
    if (!in_array('avisos_loja', $tabelas(), true)) {
        $db->exec(<<<'SQL'
CREATE TABLE avisos_loja (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(80) NOT NULL,
    texto VARCHAR(600) NULL DEFAULT NULL,
    imagem_path VARCHAR(120) NULL DEFAULT NULL,
    botao_texto VARCHAR(40) NULL DEFAULT NULL,
    botao_destino VARCHAR(30) NULL DEFAULT NULL,
    botao_url VARCHAR(500) NULL DEFAULT NULL,
    inicio DATETIME NOT NULL,
    fim DATETIME NOT NULL,
    vezes_por_dia TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    criado_por BIGINT UNSIGNED NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_avisos_loja_periodo (ativo, inicio, fim),
    CONSTRAINT chk_avisos_loja_periodo CHECK (fim > inicio),
    CONSTRAINT chk_avisos_loja_vezes CHECK (vezes_por_dia BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
    if (!in_array('avisos_loja_exibicoes', $tabelas(), true)) {
        $db->exec(<<<'SQL'
CREATE TABLE avisos_loja_exibicoes (
    id_aviso BIGINT UNSIGNED NOT NULL,
    id_usuario BIGINT UNSIGNED NOT NULL,
    dia DATE NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    vezes TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultima_em DATETIME NOT NULL,
    clicou_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id_aviso, id_usuario, dia),
    KEY idx_avisos_exibicoes_restaurante (id_aviso, id_restaurante),
    CONSTRAINT fk_avisos_exibicoes_aviso FOREIGN KEY (id_aviso) REFERENCES avisos_loja (id) ON DELETE CASCADE,
    CONSTRAINT fk_avisos_exibicoes_usuario FOREIGN KEY (id_usuario) REFERENCES `user` (id) ON DELETE CASCADE,
    CONSTRAINT fk_avisos_exibicoes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
