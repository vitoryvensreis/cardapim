<?php

declare(strict_types=1);

/**
 * Numero do dia do pedido (so visual): #1, #2, #3... zera quando a loja fecha (fim do expediente do horario de
 * funcionamento; sem horario ou "sempre aberto", a meia-noite). Uma sequencia so para delivery, retirada, balcao,
 * mesas e iFood. O numero corrido (pedidos.numero) continua igual por tras.
 * - pedidos.dia_operacional: dia do expediente em que o pedido entrou.
 * - pedidos.numero_dia: numero dentro desse dia (unico por restaurante + dia).
 * pedidos.numero_dia e criada por ultimo (Esquema::tem 'numero_dia').
 */
return static function (PDO $db): void {
    $colunas = static function () use ($db): array {
        $q = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'");
        return $q->fetchAll(PDO::FETCH_COLUMN);
    };
    $temIndice = static function (string $nome) use ($db): bool {
        $q = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = ?");
        $q->execute([$nome]);
        return (int) $q->fetchColumn() > 0;
    };

    // O MySQL faz commit implicito a cada DDL: cada passo confere se ja foi feito, para a migracao poder ser retomada.
    if (!in_array('dia_operacional', $colunas(), true)) {
        $db->exec('ALTER TABLE pedidos ADD COLUMN dia_operacional DATE NULL DEFAULT NULL AFTER numero');
    }
    if (!in_array('numero_dia', $colunas(), true)) {
        $db->exec('ALTER TABLE pedidos ADD COLUMN numero_dia INT UNSIGNED NULL DEFAULT NULL AFTER dia_operacional');
    }
    if (!$temIndice('uq_pedidos_numero_dia')) {
        $db->exec('ALTER TABLE pedidos ADD UNIQUE KEY uq_pedidos_numero_dia (id_restaurante, dia_operacional, numero_dia)');
    }
};
