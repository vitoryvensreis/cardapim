<?php

declare(strict_types=1);

/**
 * Ponto de impressao "garcom_lancar": quando o garcom toca em Lancar, os itens novos saem impressos
 * (cada item na impressora marcada no produto, entre as marcadas no ponto). So amplia o CHECK dos pontos.
 */
return static function (PDO $db): void {
    $db->exec("ALTER TABLE impressao_pontos DROP CHECK chk_impressao_pontos_ponto");
    $db->exec("ALTER TABLE impressao_pontos ADD CONSTRAINT chk_impressao_pontos_ponto
        CHECK (ponto IN ('caixa_conta', 'caixa_delivery', 'caixa_fechamento', 'garcom', 'garcom_lancar', 'delivery_novo', 'producao'))");
};
