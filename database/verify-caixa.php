<?php

declare(strict_types=1);

// Somente leitura: confirma mesas, caixas, movimentos, pagamentos e os ajustes em pedidos/itens.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-caixa.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $colunas = static function (string $tabela) use ($db): array {
        $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabela]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $exigir = static function (string $tabela, array $esperado) use ($colunas): void {
        $c = $colunas($tabela);
        if ($c === []) {
            throw new RuntimeException('Tabela ' . $tabela . ' ausente.');
        }
        foreach ($esperado as $nome => $tipo) {
            if (is_int($nome) ? !isset($c[$tipo]) : ($c[$nome] ?? '') !== $tipo) {
                throw new RuntimeException('Coluna ' . $tabela . '.' . (is_int($nome) ? $tipo : $nome) . ' incorreta ou ausente.');
            }
        }
    };
    $exigir('mesas', ['numero' => 'smallint unsigned', 'apelido', 'lugares', 'ativo', 'excluida_em']);
    $exigir('caixas', ['status' => "enum('aberto','fechado')", 'valor_abertura' => 'decimal(10,2)', 'dinheiro_esperado' => 'decimal(10,2)',
        'dinheiro_contado' => 'decimal(10,2)', 'diferenca' => 'decimal(10,2)', 'aberto_restaurante', 'resumo']);
    $exigir('caixa_movimentos', ['tipo' => "enum('entrada','saida')", 'valor' => 'decimal(10,2)', 'categoria', 'descricao', 'id_user']);
    $exigir('pedido_pagamentos', ['valor' => 'decimal(10,2)', 'troco' => 'decimal(10,2)', 'id_caixa']);
    // 'ifood' entrou depois (pagamento pelo app do iFood, migration 20261112140000).
    if (!str_starts_with((string) ($colunas('pedido_pagamentos')['forma'] ?? ''), "enum('dinheiro','debito','credito','pix','pix_online'")) {
        throw new RuntimeException('Coluna pedido_pagamentos.forma incorreta ou ausente.');
    }
    $exigir('pedidos', ['id_mesa' => 'bigint unsigned', 'id_entregador' => 'bigint unsigned', 'id_caixa' => 'bigint unsigned',
        'taxa_servico' => 'decimal(10,2)', 'servico_percentual' => 'decimal(4,2)', 'motivo_desconto', 'pessoas', 'despachado_em', 'mesa_aberta']);
    $exigir('pedido_itens', ['id_user', 'consumo_estoque', 'cancelado_em', 'motivo_cancelamento', 'id_user_cancelou']);

    $status = (string) $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'status'")->fetchColumn();
    $forma = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'forma_pagamento'")->fetch(PDO::FETCH_NUM);
    if (!str_contains($status, "'aberto'") || !str_contains((string) $forma[0], "'misto'") || $forma[1] !== 'YES') {
        throw new RuntimeException('pedidos.status/forma_pagamento sem comanda aberta ou pagamento misto.');
    }
    $unicos = $db->query(<<<'SQL'
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
  AND INDEX_NAME IN ('uq_mesas_numero', 'uq_mesas_id_restaurante', 'uq_caixas_um_aberto', 'uq_caixas_id_restaurante', 'uq_pedidos_mesa_aberta')
GROUP BY INDEX_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($unicos) !== 5) {
        throw new RuntimeException('Indices unicos de mesas/caixas ausentes (' . implode(', ', $unicos) . ').');
    }
    $checks = $db->query(<<<'SQL'
SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('chk_pedidos_valores', 'chk_pedidos_mesa', 'chk_caixas_status', 'chk_pedido_pagamentos_valores')
SQL)->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($checks) !== 4 || !str_contains((string) $checks['chk_pedidos_valores'], 'taxa_servico')) {
        throw new RuntimeException('Regras (CHECK) do caixa ausentes ou total sem a taxa de servico.');
    }
    echo "Caixa confirmado: mesas, caixas (um aberto por restaurante), entradas/saidas, pagamentos e comandas em pedidos.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do caixa: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
