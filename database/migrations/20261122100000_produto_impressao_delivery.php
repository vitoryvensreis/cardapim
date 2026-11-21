<?php

declare(strict_types=1);

/**
 * Ficha do produto e impressao:
 * - produtos.imprime_producao: 0 = o item nao sai em nenhuma impressora de producao (ex.: bebida pega no balcao).
 * - produtos.vende_delivery: 0 = so presencial (mesa/balcao): fica fora do cardapio online e do iFood
 *   (ex.: cerveja de casco retornavel). Criada por ultimo: o codigo so usa as duas quando ela existe.
 * - impressoes.tipo ganha 'cancelamento' (aviso de item ou pedido cancelado na impressora do item).
 */
return static function (PDO $db): void {
    $db->exec("ALTER TABLE impressoes DROP CHECK chk_impressoes_tipo");
    $db->exec("ALTER TABLE impressoes ADD CONSTRAINT chk_impressoes_tipo
        CHECK (tipo IN ('conta', 'pre_conta', 'itens_novos', 'pedido', 'fechamento', 'producao', 'cancelamento'))");
    $db->exec('ALTER TABLE produtos ADD COLUMN imprime_producao TINYINT(1) NOT NULL DEFAULT 1');
    $db->exec('ALTER TABLE produtos ADD COLUMN vende_delivery TINYINT(1) NOT NULL DEFAULT 1');
};
