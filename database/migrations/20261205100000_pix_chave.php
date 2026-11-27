<?php

declare(strict_types=1);

/**
 * Pix pela chave do restaurante (BR Code estatico, sem confirmacao automatica), um Pix por vez:
 * - restaurante_entrega_pagamento: pag_pix_comprovante + chave, tipo, nome de quem recebe, cidade e WhatsApp do comprovante.
 *   CHECK chk_pix_um_por_vez: Pix pelo Asaas e Pix pela chave nunca ligados juntos.
 * - pedidos.forma_pagamento ganha 'pix_comprovante' e pedidos.comprovante_enviado_em.
 * restaurante_entrega_pagamento.pix_whatsapp e criada por ultimo (Esquema::tem 'pix_chave').
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
    $q = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'forma_pagamento'");
    if (!str_contains((string) $q->fetchColumn(), 'pix_comprovante')) {
        $db->exec("ALTER TABLE pedidos MODIFY forma_pagamento ENUM('pix_online', 'pix_entrega', 'debito', 'credito', 'dinheiro', 'misto', 'ifood', 'pix_comprovante') NULL DEFAULT NULL");
    }
    if (!in_array('comprovante_enviado_em', $colunas('pedidos'), true)) {
        $db->exec('ALTER TABLE pedidos ADD COLUMN comprovante_enviado_em DATETIME NULL DEFAULT NULL AFTER pix_expira_em');
    }

    $ep = $colunas('restaurante_entrega_pagamento');
    $novas = [
        'pag_pix_comprovante' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER pag_pix_online',
        'pix_chave_tipo' => "ENUM('cpf', 'cnpj', 'celular', 'email', 'aleatoria') NULL DEFAULT NULL",
        'pix_chave' => 'VARCHAR(77) NULL DEFAULT NULL',
        'pix_recebedor' => 'VARCHAR(25) NULL DEFAULT NULL',
        'pix_cidade' => 'VARCHAR(15) NULL DEFAULT NULL',
    ];
    foreach ($novas as $coluna => $tipo) {
        if (!in_array($coluna, $ep, true)) {
            $db->exec("ALTER TABLE restaurante_entrega_pagamento ADD COLUMN $coluna $tipo");
        }
    }
    if (!$temCheck('chk_pix_um_por_vez')) {
        // Se algum restaurante tivesse os dois (impossivel pela tela), o Asaas continua e a chave desliga.
        $db->exec('UPDATE restaurante_entrega_pagamento SET pag_pix_comprovante = 0 WHERE pag_pix_online = 1 AND pag_pix_comprovante = 1');
        $db->exec('ALTER TABLE restaurante_entrega_pagamento ADD CONSTRAINT chk_pix_um_por_vez CHECK (NOT (pag_pix_online = 1 AND pag_pix_comprovante = 1))');
    }
    if (!in_array('pix_whatsapp', $ep, true)) {
        $db->exec('ALTER TABLE restaurante_entrega_pagamento ADD COLUMN pix_whatsapp VARCHAR(20) NULL DEFAULT NULL');
    }
};
