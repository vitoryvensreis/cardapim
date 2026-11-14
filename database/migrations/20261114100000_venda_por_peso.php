<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // VENDA POR PESO: o gerente escolhe como cada produto e vendido. 'un' = por unidade (inteiro, como sempre foi);
    // 'kg' / 'l' = por quilo / litro, com preco do quilo/litro e quantidade com 3 casas (grama / mililitro).
    // venda_minima: menor quantidade aceita no caixa e no garcom (ex.: 0.100 kg). NULL = qualquer quantidade > 0.
    $db->exec(<<<'SQL'
ALTER TABLE produtos
    ADD COLUMN unidade_venda ENUM('un', 'kg', 'l') NOT NULL DEFAULT 'un' AFTER preco,
    ADD COLUMN venda_minima DECIMAL(10,3) NULL DEFAULT NULL AFTER unidade_venda,
    ADD CONSTRAINT chk_produtos_venda_minima CHECK (venda_minima IS NULL OR (venda_minima > 0 AND unidade_venda <> 'un'))
SQL);

    // Porcoes fixas que o cliente escolhe no cardapio online (ex.: 0.250, 0.500, 1.000 kg). Produto por peso sem
    // porcao cadastrada nao aparece no cardapio online: vende so no caixa e no garcom. FK composta: nunca de outro restaurante.
    $db->exec(<<<'SQL'
CREATE TABLE produto_porcoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_produto BIGINT UNSIGNED NOT NULL,
    quantidade DECIMAL(10,3) NOT NULL,
    ordem TINYINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_produto_porcoes_quantidade (id_produto, quantidade),
    KEY idx_produto_porcoes_produto (id_produto, id_restaurante),
    CONSTRAINT fk_produto_porcoes_produto FOREIGN KEY (id_produto, id_restaurante) REFERENCES produtos (id, id_restaurante) ON DELETE CASCADE,
    CONSTRAINT chk_produto_porcoes_quantidade CHECK (quantidade > 0 AND quantidade <= 99.999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Item do pedido com quantidade decimal (3 casas) e a unidade copiada na hora da venda (como nome e preco).
    // Total = preco x quantidade arredondado ao centavo (0,846 kg x 59,90 = 50,6754 -> 50,68).
    $db->exec('ALTER TABLE pedido_itens DROP CHECK chk_pedido_itens_quantidade');
    $db->exec('ALTER TABLE pedido_itens DROP CHECK chk_pedido_itens_valores');
    $db->exec(<<<'SQL'
ALTER TABLE pedido_itens
    MODIFY quantidade DECIMAL(10,3) NOT NULL,
    ADD COLUMN unidade ENUM('un', 'kg', 'l') NOT NULL DEFAULT 'un' AFTER quantidade,
    ADD CONSTRAINT chk_pedido_itens_quantidade CHECK (quantidade > 0 AND quantidade <= 9999.999 AND (unidade <> 'un' OR quantidade = FLOOR(quantidade))),
    ADD CONSTRAINT chk_pedido_itens_valores CHECK (preco_unitario >= 0 AND total = ROUND(preco_unitario * quantidade, 2))
SQL);
};
