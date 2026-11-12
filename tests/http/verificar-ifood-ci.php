<?php

declare(strict_types=1);

// Apos tests/http/ifood.sh: confere no banco o que a tela nao mostra. Somente leitura e somente no banco cardapim_ci.
// - o aviso repetido do iFood nao criou pedido duplicado; avisos tratados, sem erro;
// - acoes enviadas ao iFood (aceite, pronto, cancelamento); pagamento 'ifood' do pedido concluido;
// - pedido do iFood sem WhatsApp do cliente (o telefone do iFood e 0800) e com o evento pedido.novo.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: somente no banco cardapim_ci.\n");
    exit(1);
}
$falhar = static function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
$um = static fn (string $sql, array $p = []): mixed => (function () use ($db, $sql, $p) {
    $q = $db->prepare($sql);
    $q->execute($p);
    return $q->fetchColumn();
})();

if ((int) $um("SELECT COUNT(*) FROM pedidos WHERE ifood_id = 'ped-ci-1'") !== 1) {
    $falhar('O pedido ped-ci-1 do iFood entrou mais de uma vez (ou nao entrou).');
}
if ((int) $um("SELECT COUNT(*) FROM pedidos WHERE origem = 'ifood' AND cliente_whatsapp IS NOT NULL") !== 0) {
    $falhar('Pedido do iFood com WhatsApp do cliente: avisos de WhatsApp iriam para o 0800 do iFood.');
}
if ($um("SELECT status FROM pedidos WHERE ifood_id = 'ped-ci-1'") !== 'concluido' || $um("SELECT status FROM pedidos WHERE ifood_id = 'ped-ci-2'") !== 'cancelado') {
    $falhar('Concluido e cancelado vindos do iFood nao foram aplicados.');
}
if ((int) $um("SELECT COUNT(*) FROM pedido_pagamentos pg JOIN pedidos p ON p.id = pg.id_pedido WHERE p.ifood_id = 'ped-ci-1' AND pg.forma = 'ifood' AND pg.valor = 64.29") !== 1) {
    $falhar('Pagamento do iFood (R$ 64,29) nao registrado no pedido concluido.');
}
$acoes = $db->query("SELECT CONCAT(p.ifood_id, ':', a.acao, ':', a.status) FROM ifood_acoes a JOIN pedidos p ON p.id = a.id_pedido ORDER BY a.id")->fetchAll(PDO::FETCH_COLUMN);
foreach (['ped-ci-1:confirmar:feito', 'ped-ci-1:pronto:feito', 'ped-ci-2:cancelar:feito', 'ped-ci-3:confirmar:feito'] as $esperada) {
    if (!in_array($esperada, $acoes, true)) {
        $falhar('Acao para o iFood ausente ou nao enviada: ' . $esperada . ' (fila: ' . implode(', ', $acoes) . ')');
    }
}
$pendentes = (int) $um("SELECT COUNT(*) FROM integracao_eventos WHERE provedor = 'ifood' AND (processado_em IS NULL OR erro IS NOT NULL)");
if ($pendentes !== 0) {
    $falhar($pendentes . ' aviso(s) do iFood sem tratar ou com erro.');
}
$novo = (int) $um("SELECT COUNT(*) FROM outbox_eventos o JOIN pedidos p ON p.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(o.dados, '$.id')) AS UNSIGNED)
    WHERE o.canal = 'pedidos' AND o.tipo = 'pedido.novo' AND p.ifood_id = 'ped-ci-1'");
if ($novo < 1) {
    $falhar('Pedido do iFood sem o evento pedido.novo: o aviso de pedido novo nao acenderia.');
}
echo "OK: iFood no banco (sem duplicar, acoes enviadas, pagamento, avisos tratados e aviso de pedido novo).\n";
