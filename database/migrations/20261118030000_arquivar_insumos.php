<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Nao excluir fisicamente ingredientes referenciados por fichas e movimentos.
    // EXCLUIDO_EM permite ocultar do estoque e preservar a FK nos pedidos antigos.
    $db->exec(<<<'SQL'
ALTER TABLE insumos
    ADD COLUMN excluido_em DATETIME NULL DEFAULT NULL AFTER ativo,
    ADD KEY idx_insumos_excluido (id_restaurante, excluido_em, nome)
SQL);
};
