<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // VARIAS COMANDAS POR MESA: quem chega depois ganha a propria comanda na mesma mesa, com o nome da pessoa.
    // - comanda_nome: nome dado a comanda (ex.: "Joao"). Vazio = "Comanda 1", "Comanda 2"... na ordem de abertura.
    // - A regra "uma comanda aberta por mesa" (indice unico em mesa_aberta) sai; o indice comum continua para as consultas.
    $db->exec(<<<'SQL'
ALTER TABLE pedidos
    ADD COLUMN comanda_nome VARCHAR(40) NULL DEFAULT NULL AFTER cliente_nome,
    ADD KEY idx_pedidos_mesa_aberta (mesa_aberta),
    DROP INDEX uq_pedidos_mesa_aberta
SQL);
};
