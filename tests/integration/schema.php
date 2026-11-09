<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;

$db = Database::connect();

$tables = $db->query(<<<'SQL'
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('planos', 'restaurantes')
SQL)->fetchAll(PDO::FETCH_COLUMN);

if (count($tables) !== 2) {
    throw new RuntimeException('As tabelas planos e restaurantes devem existir.');
}

$type = $db->query(<<<'SQL'
SELECT DATA_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planos' AND COLUMN_NAME = 'valor'
SQL)->fetchColumn();

if ($type !== 'decimal') {
    throw new RuntimeException('O valor dos planos deve usar DECIMAL.');
}

$foreignKey = $db->query(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'restaurantes'
    AND COLUMN_NAME = 'plano_id'
    AND REFERENCED_TABLE_NAME = 'planos'
    AND REFERENCED_COLUMN_NAME = 'id'
SQL)->fetchColumn();

$lastPaymentType = $db->query(<<<'SQL'
SELECT DATA_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes' AND COLUMN_NAME = 'ultimo_pagamento'
SQL)->fetchColumn();

if ($lastPaymentType !== 'date') {
    throw new RuntimeException('Ultimo pagamento precisa ser DATE.');
}

if ((int) $foreignKey !== 1) {
    throw new RuntimeException('A chave estrangeira restaurantes.plano_id deve existir.');
}

$db->beginTransaction();
try {
    $plan = $db->prepare('INSERT INTO planos (nome, descricao, valor, periodo) VALUES (?, ?, ?, ?)');
    $plan->execute(['Teste de integracao', 'Registro temporario', '59.90', 'mensal']);
    $planId = (int) $db->lastInsertId();

    $restaurant = $db->prepare('INSERT INTO restaurantes (nome, slug, telefone, plano_id) VALUES (?, ?, ?, ?)');
    $restaurant->execute(['Restaurante de integracao', 'teste-integracao-cardapim', '85999999999', $planId]);
    $restaurantId = (int) $db->lastInsertId();

    $check = $db->prepare('SELECT status_rest, plano_id, ultimo_pagamento FROM restaurantes WHERE id = ?');
    $check->execute([$restaurantId]);
    $saved = $check->fetch();

    if (!$saved || $saved['status_rest'] !== 'pendente' || (int) $saved['plano_id'] !== $planId || $saved['ultimo_pagamento'] !== null) {
        throw new RuntimeException('Falha na insercao ou nos valores padrao do restaurante.');
    }

    $db->prepare('UPDATE restaurantes SET ultimo_pagamento = ? WHERE id = ?')->execute(['2026-11-09', $restaurantId]);
    $date = $db->prepare('SELECT ultimo_pagamento FROM restaurantes WHERE id = ?');
    $date->execute([$restaurantId]);
    if ($date->fetchColumn() !== '2026-11-09') {
        throw new RuntimeException('Falha ao registrar data do ultimo pagamento.');
    }
    $db->rollBack();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}

echo "Schema e relacionamento validados. Nenhum dado de teste persistido.\n";
