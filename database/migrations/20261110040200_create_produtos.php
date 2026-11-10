<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Produtos vendidos. Nunca apagar (pedidos futuros referenciam): arquivar com ativo = 0.
    // disponivel = pausa manual; a falta de insumo e calculada pela ficha tecnica.
    $db->exec(<<<'SQL'
CREATE TABLE produtos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_categoria BIGINT UNSIGNED NULL,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT NULL,
    preco DECIMAL(10, 2) NOT NULL,
    codigo VARCHAR(40) NULL,
    imagem_path VARCHAR(500) NULL,
    controla_estoque TINYINT(1) NOT NULL DEFAULT 1,
    disponivel TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_produtos_id_restaurante (id, id_restaurante),
    UNIQUE KEY uq_produtos_restaurante_nome (id_restaurante, nome),
    UNIQUE KEY uq_produtos_restaurante_codigo (id_restaurante, codigo),
    KEY idx_produtos_categoria (id_categoria, id_restaurante),
    KEY idx_produtos_cardapio (id_restaurante, ativo, disponivel, ordem),
    CONSTRAINT fk_produtos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_produtos_categoria FOREIGN KEY (id_categoria, id_restaurante)
        REFERENCES categorias (id, id_restaurante),
    CONSTRAINT chk_produtos_preco CHECK (preco >= 0),
    CONSTRAINT chk_produtos_flags CHECK (controla_estoque IN (0, 1) AND disponivel IN (0, 1) AND ativo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
