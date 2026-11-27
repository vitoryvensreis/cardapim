<?php

declare(strict_types=1);

/**
 * Popup do cardapio online: uma linha por restaurante (sem linha = sem popup).
 * - tipo imagem: banner quadrado (1:1) em storage/uploads/popups, servido por /midia/popups/...;
 * - tipo texto: titulo e mensagem.
 * inicio/fim: periodo opcional (datas inclusivas). versao sobe a cada salvamento: o cliente ve o popup de novo
 * quando o restaurante muda. Tabela criada por ultimo (Esquema::tem 'popup_cardapio').
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE restaurante_popup (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    tipo ENUM('imagem', 'texto') NOT NULL DEFAULT 'imagem',
    imagem_path VARCHAR(120) NULL DEFAULT NULL,
    titulo VARCHAR(60) NULL DEFAULT NULL,
    texto VARCHAR(300) NULL DEFAULT NULL,
    inicio DATE NULL DEFAULT NULL,
    fim DATE NULL DEFAULT NULL,
    versao INT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_restaurante),
    CONSTRAINT fk_popup_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE,
    CONSTRAINT chk_popup_periodo CHECK (inicio IS NULL OR fim IS NULL OR fim >= inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
