<?php

declare(strict_types=1);

// Somente leitura: confirma a tabela de posicao dos entregadores e as mesas iniciais dos restaurantes.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-entregas.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $q = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entregador_posicoes'");
    $c = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['id_user' => 'bigint unsigned', 'id_restaurante' => 'bigint unsigned', 'latitude' => 'decimal(10,7)', 'longitude' => 'decimal(10,7)', 'atualizado_em' => 'datetime'] as $nome => $tipo) {
        if (($c[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna entregador_posicoes.' . $nome . ' incorreta ou ausente.');
        }
    }
    $pk = $db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entregador_posicoes' AND CONSTRAINT_NAME = 'PRIMARY'")->fetchAll(PDO::FETCH_COLUMN);
    if ($pk !== ['id_user']) {
        throw new RuntimeException('entregador_posicoes deve guardar so a ultima posicao por entregador (PK id_user).');
    }
    $semMesa = (int) $db->query('SELECT COUNT(*) FROM restaurantes r WHERE NOT EXISTS (SELECT 1 FROM mesas m WHERE m.id_restaurante = r.id)')->fetchColumn();
    if ($semMesa > 0) {
        throw new RuntimeException($semMesa . ' restaurante(s) sem mesas.');
    }
    echo "Entregas confirmadas: posicao do entregador (so a ultima) e mesas iniciais em todos os restaurantes.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao das entregas: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
