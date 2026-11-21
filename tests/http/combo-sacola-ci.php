<?php

declare(strict_types=1);

// Confere a sacola do cardapio online com um combo (mesma regra usada ao gravar o pedido), sem criar pedido
// (o limite de pedidos por IP do CI ja e usado pelos outros testes). SOMENTE no banco de CI.
// Uso: php tests/http/combo-sacola-ci.php <slug> <id_combo> <id_opcao>:<qtd> [...]  -> "total linhas"
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: somente no banco cardapim_ci.\n");
    exit(1);
}
$q = $db->prepare('SELECT id FROM restaurantes WHERE slug = ?');
$q->execute([(string) ($argv[1] ?? '')]);
$idRest = (int) $q->fetchColumn();
$escolhas = [];
foreach (array_slice($argv, 3) as $par) {
    [$id, $qtd] = array_map('intval', explode(':', $par));
    $escolhas[] = ['id' => $id, 'quantidade' => $qtd];
}
$r = \Cardapim\Services\Pedidos\LinhasSacola::completar($db, $idRest, [
    ['id' => (int) ($argv[2] ?? 0), 'quantidade' => 1, 'porcao' => null, 'observacao' => '', 'complementos' => $escolhas],
]);
$l = $r['linhas'][0] ?? null;
if ($l === null || !isset($l['combo'])) {
    echo 'invalida ' . ($r['mensagem'] ?? '') . "\n";
    exit(0);
}
$soma = array_sum(array_map(static fn (array $x): int => $x['centavos'] * $x['unidades'], $l['combo']['linhas']));
echo $l['total'] . ' ' . count($l['combo']['linhas']) . ' ' . $soma . "\n";
