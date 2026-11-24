<?php

declare(strict_types=1);

// Rotina diaria (cron) da cobranca das assinaturas: bloqueia restaurantes com fatura atrasada alem da tolerancia.
// As telas e o cardapio ja conferem na hora; esta rotina so garante o bloqueio de quem ninguem abriu.
// Uso (usuario do site): php8.4 /home/<usuario>/apps/cardapim/current/bin/cobranca-diaria.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Services\Plataforma\AssinaturaCobranca;

try {
    $db = Database::connect();
    $ids = $db->query("SELECT DISTINCT f.id_restaurante FROM assinatura_faturas f JOIN restaurantes r ON r.id = f.id_restaurante
        WHERE f.status IN ('pendente', 'vencida') AND r.status_rest IN ('ativo', 'pendente')
        UNION SELECT a.id_restaurante FROM assinaturas a JOIN restaurantes r ON r.id = a.id_restaurante
        WHERE a.status = 'cancelada' AND (a.vence_em IS NULL OR a.vence_em < CURRENT_DATE)
        AND r.status_rest IN ('ativo', 'pendente')")->fetchAll(PDO::FETCH_COLUMN);
    $cobranca = new AssinaturaCobranca($db);
    $bloqueados = 0;
    foreach ($ids as $id) {
        if ($cobranca->conferirAtraso((int) $id)) {
            $bloqueados++;
        }
    }
    echo date('Y-m-d H:i:s') . " Cobranca diaria: " . count($ids) . " com fatura em aberto, {$bloqueados} bloqueado(s).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cobranca diaria falhou: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
