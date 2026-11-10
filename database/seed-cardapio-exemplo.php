<?php

declare(strict_types=1);

// Cria (--apply) e confere (--verify) o cardapio de exemplo do Restaurante Modelo. Idempotente.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--apply', '--verify'], true)) {
    fwrite(STDERR, "Uso: php8.4 database/seed-cardapio-exemplo.php --apply|--verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Modules\Inventory\DisponibilidadeProdutos;

try {
    $db = \Cardapim\Core\Database::connect();
    if ($argv[1] === '--apply') {
        $db->beginTransaction();
        try {
            $criados = (require __DIR__ . '/seeders/cardapio_exemplo_modelo.php')($db);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        printf("Criados agora: %d categorias, %d insumos, %d produtos, %d linhas de ficha tecnica.\n",
            $criados['categorias'], $criados['insumos'], $criados['produtos'], $criados['ficha']);
    }

    $idRestaurante = (int) $db->query("SELECT id FROM restaurantes WHERE slug = 'restaurante-modelo-cardapim'")->fetchColumn();
    // Custo pela ficha tecnica, calculado pelo proprio MySQL em DECIMAL (exato).
    $linhas = $db->prepare(<<<'SQL'
SELECT p.id, p.nome, p.preco, c.nome AS categoria, COUNT(pi.id) AS itens_ficha,
       ROUND(SUM(pi.quantidade * i.custo_unitario), 2) AS custo
FROM produtos p
JOIN categorias c ON c.id = p.id_categoria
LEFT JOIN produto_insumos pi ON pi.id_produto = p.id
LEFT JOIN insumos i ON i.id = pi.id_insumo
WHERE p.id_restaurante = ?
GROUP BY p.id, p.nome, p.preco, c.nome, c.ordem, p.ordem
ORDER BY c.ordem, p.ordem
SQL);
    $linhas->execute([$idRestaurante]);
    $produtos = $linhas->fetchAll(PDO::FETCH_ASSOC);

    $esperado = [
        'X-Burguer' => 6, 'Batata frita média' => 4, 'Batata frita grande' => 4, 'Espetinho de carne' => 3,
        'Espetinho de frango' => 1, 'Cerveja lata 350 ml' => 1, 'Refrigerante lata 350 ml' => 1,
    ];
    $porNome = array_column($produtos, null, 'nome');
    foreach ($esperado as $nome => $itens) {
        if (!isset($porNome[$nome]) || (int) $porNome[$nome]['itens_ficha'] < 1) {
            throw new RuntimeException("Produto de exemplo ausente ou sem ficha tecnica: $nome.");
        }
    }
    $hamburguer = $db->prepare(<<<'SQL'
SELECT i.nome, pi.quantidade, i.unidade FROM produto_insumos pi
JOIN insumos i ON i.id = pi.id_insumo JOIN produtos p ON p.id = pi.id_produto
WHERE p.id_restaurante = ? AND p.nome = 'X-Burguer'
SQL);
    $hamburguer->execute([$idRestaurante]);
    $ficha = array_column($hamburguer->fetchAll(PDO::FETCH_ASSOC), null, 'nome');
    if (($ficha['Carne moída para hambúrguer']['quantidade'] ?? null) !== '0.080'
        || ($ficha['Queijo muçarela (fatia)']['quantidade'] ?? null) !== '2.000'
        || ($ficha['Pão de hambúrguer']['quantidade'] ?? null) !== '1.000') {
        throw new RuntimeException('Ficha tecnica do X-Burguer diferente do esperado.');
    }

    $disponibilidade = (new DisponibilidadeProdutos($db))->verificar($idRestaurante, array_map('intval', array_column($produtos, 'id')));
    echo str_pad('Produto', 28) . str_pad('Preço', 10) . str_pad('Custo', 10) . str_pad('Margem', 9) . "Pode vender\n";
    foreach ($produtos as $p) {
        $d = $disponibilidade[(int) $p['id']];
        $margem = (float) $p['preco'] > 0 ? (1 - (float) $p['custo'] / (float) $p['preco']) * 100 : 0;
        printf("%s%s%s%s%s\n",
            str_pad($p['nome'], 28 + strlen($p['nome']) - mb_strlen($p['nome'])),
            str_pad('R$ ' . number_format((float) $p['preco'], 2, ',', '.'), 10),
            str_pad('R$ ' . number_format((float) $p['custo'], 2, ',', '.'), 10),
            str_pad(number_format($margem, 0) . '%', 9),
            $d['disponivel'] ? $d['porcoes'] . ' un' : 'INDISPONIVEL (' . $d['motivo'] . ')');
        if (isset($esperado[$p['nome']]) && !$d['disponivel']) {
            throw new RuntimeException('Produto de exemplo indisponivel: ' . $p['nome'] . '.');
        }
    }
    echo "Cardapio de exemplo do Restaurante Modelo conferido.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha no cardapio de exemplo: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
