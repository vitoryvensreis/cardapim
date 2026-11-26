<?php
declare(strict_types=1);

/**
 * Etapas 06 a 08 do fiscal:
 * - impressoes aceita o tipo "nfce" (DANFE NFC-e pelo Deliveryzim Agent);
 * - fiscal_liberacoes ganha a liberação de PRODUÇÃO, dada só pelo superadmin, por restaurante.
 * Alterações aditivas; nenhum dado existente muda (produção começa bloqueada para todos).
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE impressoes DROP CHECK chk_impressoes_tipo');
    $db->exec("ALTER TABLE impressoes ADD CONSTRAINT chk_impressoes_tipo
        CHECK (tipo IN ('conta', 'pre_conta', 'itens_novos', 'pedido', 'fechamento', 'producao', 'cancelamento', 'nfce'))");
    $db->exec('ALTER TABLE fiscal_liberacoes
        ADD COLUMN producao TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN producao_autor BIGINT UNSIGNED NULL DEFAULT NULL,
        ADD COLUMN producao_em DATETIME NULL DEFAULT NULL');
};
