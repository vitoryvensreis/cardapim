<?php

declare(strict_types=1);

// Rotina do iFood (cron a cada minuto, usuario do site): recebe os pedidos e avisos do iFood a cada 30 segundos
// (o iFood exige essa frequencia para manter a loja aberta), envia o que a loja fez (aceitar, pronto,
// despachar, cancelar) e a disponibilidade dos itens pelo estoque.
// Uso: * * * * * php8.4 /home/<usuario>/apps/cardapim/current/bin/ifood.php >> /home/<usuario>/apps/cardapim/shared/storage/logs/ifood.log 2>&1
// Opcao --uma-vez: uma rodada so (testes).
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Ifood\IfoodConta;
use Cardapim\Services\Ifood\RotinaIfood;

if (!IfoodApi::disponivel()) {
    exit(0); // IFOOD_CLIENT_ID/SECRET ainda nao configurados: nada a fazer
}

// Uma rotina por vez: se a anterior ainda estiver rodando, esta sai sem fazer nada.
$trava = fopen(sys_get_temp_dir() . '/cardapim-ifood-' . md5(dirname(__DIR__)) . '.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$rodadas = in_array('--uma-vez', $argv, true) ? 1 : 2;
$inicio = time();
try {
    for ($rodada = 0; $rodada < $rodadas; $rodada++) {
        if ($rodada > 0) {
            $espera = $inicio + 30 - time();
            if ($espera > 0) {
                sleep($espera);
            }
        }
        $db = Database::connect();
        $rotina = new RotinaIfood($db);
        foreach (IfoodConta::restaurantesConectados($db) as $id) {
            $r = $rotina->rodar($id);
            if ($r['avisos'] > 0 || $r['acoes'] > 0 || $r['itens'] > 0 || $r['erro'] !== null) {
                echo date('Y-m-d H:i:s') . sprintf(' iFood restaurante %d: %d aviso(s), %d tratado(s), %d acao(oes), %d item(ns)%s', $id, $r['avisos'], $r['tratados'], $r['acoes'], $r['itens'],
                    $r['erro'] !== null ? '. Erro: ' . $r['erro'] : '') . "\n";
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' Rotina do iFood falhou: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    flock($trava, LOCK_UN);
}
