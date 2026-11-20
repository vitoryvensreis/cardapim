<?php

declare(strict_types=1);

/**
 * Impressao por produto (vias de producao):
 * - produto_impressoras: em quais impressoras (pelos apelidos do Agent: Churrasqueira, Cozinha, Bar...) o produto sai
 *   na via de producao. Sem marcacao, o item vai para a impressora padrao do ponto "producao".
 * - impressao_pontos ganha o ponto 'producao' (impressora padrao dos itens sem impressora marcada).
 * - impressoes.lote: os cupons de uma mesma impressao dividida por estacao (situacao unica na tela).
 * - impressoes.tipo ganha 'producao' (vias do delivery quando o pedido e aceito).
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE produto_impressoras (
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NOT NULL,
    id_impressora BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id_produto, id_impressora),
    KEY idx_produto_impressoras_restaurante (id_restaurante),
    KEY idx_produto_impressoras_impressora (id_impressora),
    CONSTRAINT fk_produto_impressoras_produto FOREIGN KEY (id_produto, id_restaurante)
        REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT fk_produto_impressoras_impressora FOREIGN KEY (id_impressora) REFERENCES impressoras (id) ON DELETE CASCADE,
    CONSTRAINT fk_produto_impressoras_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $db->exec("ALTER TABLE impressao_pontos DROP CHECK chk_impressao_pontos_ponto");
    $db->exec("ALTER TABLE impressao_pontos ADD CONSTRAINT chk_impressao_pontos_ponto
        CHECK (ponto IN ('caixa_conta', 'caixa_delivery', 'caixa_fechamento', 'garcom', 'delivery_novo', 'producao'))");
    $db->exec("ALTER TABLE impressoes DROP CHECK chk_impressoes_tipo");
    $db->exec("ALTER TABLE impressoes ADD CONSTRAINT chk_impressoes_tipo
        CHECK (tipo IN ('conta', 'pre_conta', 'itens_novos', 'pedido', 'fechamento', 'producao'))");
    $db->exec('ALTER TABLE impressoes ADD COLUMN lote CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER id_impressora,
        ADD KEY idx_impressoes_lote (id_restaurante, lote)');
};
