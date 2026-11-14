<?php

declare(strict_types=1);

// Integracao (MySQL): venda por peso. Regras do banco (porcoes isoladas por restaurante, venda minima so em
// produto por peso) e a conta do valor: o arredondamento do PHP (QuantidadeVenda::totalCentavos) tem que bater
// com o ROUND do MySQL usado no CHECK de pedido_itens. Tudo numa transacao desfeita no final.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Inventory\QuantidadeVenda;

$db = Database::connect();
$fail = static function (string $msg): never {
    throw new RuntimeException($msg);
};
$recusa = static function (callable $acao, string $descricao) use ($fail): void {
    try {
        $acao();
    } catch (PDOException) {
        return;
    }
    $fail("Banco aceitou: $descricao");
};

$db->beginTransaction();
try {
    $planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
    $rest = $db->prepare('INSERT INTO restaurantes (nome, slug, plano_id, status_rest) VALUES (?, ?, ?, ?)');
    $rest->execute(['CI Peso A', 'ci-peso-a', $planId, 'ativo']);
    $a = (int) $db->lastInsertId();
    $rest->execute(['CI Peso B', 'ci-peso-b', $planId, 'ativo']);
    $b = (int) $db->lastInsertId();

    $prod = $db->prepare('INSERT INTO produtos (id_restaurante, nome, preco, unidade_venda, venda_minima, controla_estoque) VALUES (?, ?, ?, ?, ?, 0)');
    $prod->execute([$a, 'Picanha', '59.90', 'kg', '0.100']);
    $picanha = (int) $db->lastInsertId();
    $recusa(fn () => $prod->execute([$a, 'Lata', '8.00', 'un', '0.500']), 'venda minima em produto por unidade');
    $recusa(fn () => $prod->execute([$a, 'Outro', '8.00', 'cx', null]), 'unidade de venda desconhecida');

    $porcao = $db->prepare('INSERT INTO produto_porcoes (id_restaurante, id_produto, quantidade, ordem) VALUES (?, ?, ?, ?)');
    $porcao->execute([$a, $picanha, '0.250', 0]);
    $porcao->execute([$a, $picanha, '0.500', 1]);
    $recusa(fn () => $porcao->execute([$a, $picanha, '0.500', 2]), 'porcao repetida');
    $recusa(fn () => $porcao->execute([$b, $picanha, '1.000', 0]), 'porcao apontando para produto de outro restaurante');
    $recusa(fn () => $porcao->execute([$a, $picanha, '0.000', 3]), 'porcao zero');

    // O valor calculado no PHP e o ROUND do MySQL (CHECK de pedido_itens) sempre iguais.
    $conta = $db->prepare('SELECT ROUND(CAST(? AS DECIMAL(10,2)) * CAST(? AS DECIMAL(10,3)), 2)');
    mt_srand(20261114);
    for ($i = 0; $i < 300; $i++) {
        $centavos = mt_rand(1, 999999);
        $milesimos = mt_rand(1, 99999);
        $conta->execute([sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100), QuantidadeVenda::paraBanco($milesimos)]);
        $mysql = (string) $conta->fetchColumn();
        $php = QuantidadeVenda::totalCentavos($centavos, $milesimos);
        if ($mysql !== sprintf('%d.%02d', intdiv($php, 100), $php % 100)) {
            $fail("Arredondamento diferente: $centavos x $milesimos (MySQL $mysql, PHP $php)");
        }
    }

    $db->rollBack();
    echo "Venda por peso no MySQL: porcoes isoladas, venda minima e arredondamento iguais ao PHP.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Falha na integracao da venda por peso: ' . $e->getMessage() . "\n");
    exit(1);
}
