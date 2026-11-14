<?php

declare(strict_types=1);

// Somente leitura: confirma a venda por peso (produtos.unidade_venda, produto_porcoes e pedido_itens decimal).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-peso.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $exigir = static function (string $tabela, array $esperado) use ($db): void {
        $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabela]);
        $c = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($esperado as $nome => $tipo) {
            if (($c[$nome] ?? '') !== $tipo) {
                throw new RuntimeException('Coluna ' . $tabela . '.' . $nome . ' incorreta ou ausente.');
            }
        }
    };
    $exigir('produtos', ['unidade_venda' => "enum('un','kg','l')", 'venda_minima' => 'decimal(10,3)']);
    $exigir('produto_porcoes', ['id_restaurante' => 'bigint unsigned', 'id_produto' => 'bigint unsigned', 'quantidade' => 'decimal(10,3)']);
    $exigir('pedido_itens', ['quantidade' => 'decimal(10,3)', 'unidade' => "enum('un','kg','l')"]);
    $checks = $db->query("SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('chk_pedido_itens_valores', 'chk_pedido_itens_quantidade', 'chk_produtos_venda_minima')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($checks) !== 3 || !str_contains(strtolower((string) $checks['chk_pedido_itens_valores']), 'round')) {
        throw new RuntimeException('Regras (CHECK) da venda por peso ausentes.');
    }
    $fk = $db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_produto_porcoes_produto'")->fetchColumn();
    if ((int) $fk !== 1) {
        throw new RuntimeException('Ligacao produto_porcoes -> produtos ausente.');
    }
    $quebrados = (int) $db->query("SELECT COUNT(*) FROM pedido_itens WHERE unidade = 'un' AND quantidade <> FLOOR(quantidade)")->fetchColumn();
    if ($quebrados !== 0) {
        throw new RuntimeException('Itens por unidade com quantidade quebrada.');
    }
    echo "Venda por peso confirmada: unidade de venda, porcoes e itens com 3 casas.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao da venda por peso: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
