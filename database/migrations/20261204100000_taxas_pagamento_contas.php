<?php

declare(strict_types=1);

/**
 * Taxa por forma de pagamento em todos os fechamentos de conta (mesa, balcao, garcom e Pix da mesa).
 * A taxa e cobrada em CADA pagamento (a conta pode ser paga em partes, em formas diferentes):
 * - pedido_pagamentos.taxa: parte do valor que e taxa (valor = parte da conta + taxa). Dinheiro nunca tem taxa.
 * - pedido_pagamentos.taxa_rotulo: foto da regra no momento do pagamento (ex.: "Taxa do crédito (3,5%)").
 * - comanda_pix.taxa / taxa_rotulo: taxa somada ao QR Code do Pix da mesa (Asaas).
 * pedido_pagamentos.taxa_rotulo e criada por ultimo (Esquema::tem 'taxas_contas').
 */
return static function (PDO $db): void {
    $colunas = static function (string $tabela) use ($db): array {
        $q = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabela]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    };
    $temCheck = static function (string $nome) use ($db): bool {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?');
        $q->execute([$nome]);
        return (int) $q->fetchColumn() === 1;
    };

    // O MySQL faz commit implicito a cada DDL: cada passo confere se ja foi feito, para a migracao poder ser retomada.
    $pix = $colunas('comanda_pix');
    if (!in_array('taxa', $pix, true)) {
        $db->exec("ALTER TABLE comanda_pix ADD COLUMN taxa DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER valor");
    }
    if (!in_array('taxa_rotulo', $pix, true)) {
        $db->exec('ALTER TABLE comanda_pix ADD COLUMN taxa_rotulo VARCHAR(60) NULL DEFAULT NULL AFTER taxa');
    }

    $pag = $colunas('pedido_pagamentos');
    if (!in_array('taxa', $pag, true)) {
        $db->exec('ALTER TABLE pedido_pagamentos ADD COLUMN taxa DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER valor');
    }
    if (!$temCheck('chk_pedido_pagamentos_taxa')) {
        $db->exec("ALTER TABLE pedido_pagamentos ADD CONSTRAINT chk_pedido_pagamentos_taxa
            CHECK (taxa >= 0 AND taxa < valor AND (taxa = 0 OR forma <> 'dinheiro'))");
    }
    if (!in_array('taxa_rotulo', $pag, true)) {
        $db->exec('ALTER TABLE pedido_pagamentos ADD COLUMN taxa_rotulo VARCHAR(60) NULL DEFAULT NULL AFTER taxa');
    }
};
