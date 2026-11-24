<?php

declare(strict_types=1);

// Conciliação das assinaturas já vinculadas. Nunca cria clientes ou cobranças.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Services\Plataforma\AssinaturaCobranca;

try {
    $db = Database::connect();
    $trava = $db->query("SELECT GET_LOCK('cardapim:conciliacao-assinaturas', 0)");
    if ((int) $trava->fetchColumn() !== 1) {
        echo "Conciliação já em execução.\n";
        exit(0);
    }
    try {
        $cobranca = new AssinaturaCobranca($db);
        $conta = $cobranca->conta();
        if ($conta === null) {
            fwrite(STDERR, "Asaas da plataforma indisponível. Nenhuma alteração executada.\n");
            exit(1);
        }
        $cursor = 0;
        $ok = 0;
        $falhas = 0;
        do {
            $q = $db->prepare("SELECT a.id FROM assinaturas a
                WHERE a.id > ? AND a.ambiente = ? AND a.asaas_assinatura_id IS NOT NULL
                AND a.status IN ('ativa', 'aguardando')
                AND a.id = (SELECT MAX(b.id) FROM assinaturas b
                    WHERE b.id_restaurante = a.id_restaurante AND b.status IN ('ativa', 'aguardando', 'cancelada'))
                ORDER BY a.id LIMIT 50");
            $q->execute([$cursor, $conta['ambiente']]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $cursor = (int) $id;
                try {
                    if ($cobranca->sincronizar($cursor)) {
                        $ok++;
                    } else {
                        $falhas++;
                    }
                } catch (Throwable $erro) {
                    $falhas++;
                    error_log('[Cardapim] Conciliação assinatura ' . $cursor . ': ' . get_class($erro));
                }
                // Evita rajada de consultas à API. Webhooks continuam sendo o fluxo principal.
                usleep(250000);
            }
        } while (count($ids) === 50);
        echo date('Y-m-d H:i:s') . " Conciliação: {$ok} consultadas, {$falhas} falhas.\n";
    } finally {
        $db->query("SELECT RELEASE_LOCK('cardapim:conciliacao-assinaturas')");
    }
    exit($falhas > 0 ? 1 : 0);
} catch (Throwable $erro) {
    fwrite(STDERR, 'Conciliação falhou: ' . get_class($erro) . "\n");
    exit(1);
}
