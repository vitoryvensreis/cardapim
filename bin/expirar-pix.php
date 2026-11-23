<?php

declare(strict_types=1);

// Cancela cobrancas Pix de pedidos online vencidas ha 60 minutos.
// Cron (usuario do site): * * * * * php8.4 /home/<usuario>/apps/cardapim/current/bin/expirar-pix.php
// Consulta o Asaas primeiro: se pago, confirma; se pendente, remove no Asaas e cancela localmente.
// Uma falha de API deixa o pedido pendente para a proxima rodada, sem perder o controle financeiro.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Services\Pedidos\PixPedido;

// Impede sobreposicao entre rodadas, inclusive se o Asaas estiver lento.
$trava = fopen(sys_get_temp_dir() . '/cardapim-expirar-pix-' . md5(dirname(__DIR__)) . '.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$erros = 0;
$contagem = ['cancelado' => 0, 'confirmado' => 0, 'ignorado' => 0];
try {
    $db = Database::connect();
    // Lote limitado: evita sobrecarregar o Asaas e o banco se houver acumulacao.
    $q = $db->prepare("SELECT id, id_restaurante FROM pedidos
        WHERE forma_pagamento = 'pix_online' AND status = 'aguardando_pagamento'
          AND pagamento_status = 'pendente' AND pix_cobranca_id IS NOT NULL
          AND pix_expira_em IS NOT NULL AND pix_expira_em <= ?
        ORDER BY pix_expira_em ASC, id ASC LIMIT 100");
    $q->execute([date('Y-m-d H:i:s')]);
    $pix = new PixPedido($db);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $pedido) {
        $idRestaurante = (int) $pedido['id_restaurante'];
        $idPedido = (int) $pedido['id'];
        try {
            $resultado = $pix->expirar($idRestaurante, $idPedido);
            $contagem[$resultado]++;
        } catch (Throwable $erro) {
            $erros++;
            error_log(sprintf('[Cardapim] Expiracao Pix falhou: restaurante %d pedido %d (%s).',
                $idRestaurante, $idPedido, get_class($erro)));
        }
    }
    if (array_sum($contagem) > 0 || $erros > 0) {
        echo date('Y-m-d H:i:s') . sprintf(
            ' Pix online: %d cancelado(s), %d confirmado(s), %d ignorado(s), %d erro(s).' . "\n",
            $contagem['cancelado'], $contagem['confirmado'], $contagem['ignorado'], $erros
        );
    }
} catch (Throwable $erro) {
    error_log('[Cardapim] Rotina de expiracao Pix indisponivel: ' . get_class($erro));
    exit(1);
} finally {
    flock($trava, LOCK_UN);
    fclose($trava);
}
exit($erros > 0 ? 1 : 0);
