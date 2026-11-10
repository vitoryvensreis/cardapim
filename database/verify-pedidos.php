<?php

declare(strict_types=1);

// Somente leitura: confirma as tabelas de pedidos, itens, entrega e pagamento.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-pedidos.php --verify\n");
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
    $exigir('restaurante_entrega_pagamento', ['aceita_entrega', 'aceita_retirada', 'pag_pix_online', 'pag_pix_entrega', 'pag_debito', 'pag_credito', 'pag_dinheiro']);
    $exigir('restaurante_areas_entrega', ['tipo' => "enum('bairro','raio')", 'raio_km' => 'decimal(5,2)', 'taxa' => 'decimal(10,2)', 'pedido_minimo' => 'decimal(10,2)', 'bairro', 'cidade', 'chave']);
    // Todo pedido diz de qual restaurante e de quem (cliente e/ou pessoa da equipe).
    $exigir('pedidos', ['id_restaurante' => 'bigint unsigned', 'id_cliente' => 'bigint unsigned', 'id_user' => 'bigint unsigned', 'numero' => 'int unsigned',
        'subtotal' => 'decimal(10,2)', 'taxa_entrega' => 'decimal(10,2)', 'desconto' => 'decimal(10,2)', 'total' => 'decimal(10,2)', 'troco_para' => 'decimal(10,2)',
        'chave_idempotencia' => 'char(32)', 'status', 'forma_pagamento', 'pagamento_status', 'consumo_estoque', 'pix_cobranca_id']);
    $exigir('pedido_itens', ['id_pedido' => 'bigint unsigned', 'id_restaurante' => 'bigint unsigned', 'id_produto' => 'bigint unsigned', 'preco_unitario' => 'decimal(10,2)', 'total' => 'decimal(10,2)', 'nome', 'quantidade']);
    $exigir('pedido_eventos', ['id_pedido', 'id_restaurante', 'status', 'descricao', 'id_user', 'id_cliente']);
    $exigir('asaas_clientes', ['id_restaurante', 'id_cliente', 'ambiente', 'id_externo']);

    $nulo = $db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'id_restaurante'")->fetchColumn();
    if ($nulo !== 'NO') {
        throw new RuntimeException('pedidos.id_restaurante deve ser obrigatorio.');
    }
    $unicos = $db->query(<<<'SQL'
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
  AND INDEX_NAME IN ('uq_pedidos_numero', 'uq_pedidos_idempotencia', 'uq_pedidos_id_restaurante', 'uq_areas_entrega_chave', 'uq_asaas_clientes')
GROUP BY INDEX_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($unicos) !== 5) {
        throw new RuntimeException('Indices unicos de pedidos/entrega ausentes.');
    }
    // FKs compostas: item e evento nunca apontam para pedido/produto de outro restaurante.
    $fks = $db->query(<<<'SQL'
SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'id_restaurante'
  AND CONSTRAINT_NAME IN ('fk_pedido_itens_pedido', 'fk_pedido_itens_produto', 'fk_pedido_eventos_pedido')
GROUP BY CONSTRAINT_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($fks) !== 3) {
        throw new RuntimeException('Chaves estrangeiras compostas (com id_restaurante) ausentes.');
    }
    $regras = (int) $db->query(<<<'SQL'
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
  AND TABLE_NAME IN ('pedidos', 'pedido_itens', 'restaurante_areas_entrega', 'restaurante_entrega_pagamento')
SQL)->fetchColumn();
    if ($regras < 14) {
        throw new RuntimeException('Regras (CHECK) de pedidos/entrega ausentes (' . $regras . ').');
    }
    echo "Pedidos confirmados: tabelas, restaurante e cliente em cada pedido, indices unicos, FKs compostas e regras.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao dos pedidos: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
