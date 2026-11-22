<?php

declare(strict_types=1);

/**
 * Aparencia do cupom (Gerencia > Impressoras): fonte, peso do texto, peso dos destaques e tamanho da letra de cada
 * restaurante. Sem linha = padrao do Deliveryzim (Roboto Mono, Regular e Negrito, letra Normal).
 * impressoras.papel_informado: o Agent 0.9.0+ ja mandou o papel desta impressora (sem isso a tela avisa "nao informado").
 * Tabela criada por ultimo (Esquema::tem 'cupom_aparencia').
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE impressoras ADD COLUMN papel_informado TINYINT(1) NOT NULL DEFAULT 0 AFTER margem_corte');
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_cupom_aparencia (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    fonte VARCHAR(20) NOT NULL,
    peso_texto SMALLINT UNSIGNED NOT NULL,
    peso_destaque SMALLINT UNSIGNED NOT NULL,
    tamanho VARCHAR(10) NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_cupom_aparencia_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT chk_cupom_aparencia_pesos CHECK (peso_texto BETWEEN 100 AND 900 AND peso_destaque BETWEEN 100 AND 900)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
