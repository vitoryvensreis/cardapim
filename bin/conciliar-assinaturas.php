<?php

declare(strict_types=1);

// Conciliação seletiva das assinaturas e faturas recentes. Nunca cria clientes ou cobranças.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
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
        $repo = new AssinaturaRepository($db);
        $desde = date('Y-m-d', strtotime('-7 days'));
        $ok = 0;
        $falhas = 0;
        // Apenas períodos próximos e faturas recentes. Histórico completo é manual.
        foreach (['recorrencias', 'faturas'] as $fase) {
            $cursor = 0;
            do {
                $lote = $fase === 'recorrencias'
                    ? $repo->loteRecorrenciasConciliacao($conta['ambiente'], $cursor, $desde)
                    : $repo->loteFaturasConciliacao($conta['ambiente'], $cursor, $desde);
                foreach ($lote as $item) {
                    $cursor = $fase === 'recorrencias' ? $item : (int) $item['id'];
                    try {
                        $resultado = $fase === 'recorrencias'
                            ? $cobranca->sincronizar($item)
                            : $cobranca->sincronizarFatura($conta['ambiente'], $item['asaas_id']);
                        $resultado ? $ok++ : $falhas++;
                    } catch (Throwable $erro) {
                        $falhas++;
                        error_log('[Cardapim] Conciliação ' . $fase . ' #' . $cursor . ': ' . get_class($erro));
                        if ((int) $erro->getCode() === 429) {
                            throw $erro;
                        }
                    }
                    usleep(250000);
                }
            } while (count($lote) === 25);
        }
        echo date('Y-m-d H:i:s') . " Conciliação seletiva: {$ok} consultadas, {$falhas} falhas.\n";
    } finally {
        $db->query("SELECT RELEASE_LOCK('cardapim:conciliacao-assinaturas')");
    }
    exit($falhas > 0 ? 1 : 0);
} catch (Throwable $erro) {
    fwrite(STDERR, 'Conciliação falhou: ' . get_class($erro) . "\n");
    exit(1);
}
