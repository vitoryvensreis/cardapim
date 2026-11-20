<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // O gerente decide se o entregador ve no painel dele quanto ganhou em taxas de entrega
    // (resumo de hoje/7 dias e a taxa de cada entrega feita). Padrao 1 = continua vendo, como antes.
    // O valor a receber do cliente (total do pedido) continua sempre visivel: o entregador precisa cobrar.
    $db->exec(<<<'SQL'
ALTER TABLE restaurante_entrega_pagamento
    ADD COLUMN entregador_ve_valores TINYINT(1) NOT NULL DEFAULT 1 AFTER pag_dinheiro,
    ADD CONSTRAINT chk_entrega_pagamento_entregador_valores CHECK (entregador_ve_valores IN (0, 1))
SQL);
};
