<?php

declare(strict_types=1);

// Somente leitura: confirma as tabelas dos complementos, do item do pedido e do cardapio publicado no iFood.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-complementos.php --verify\n");
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
    $exigir('complemento_grupos', ['id_restaurante' => 'bigint unsigned', 'minimo' => 'tinyint unsigned', 'maximo' => 'tinyint unsigned']);
    $exigir('complementos', ['id_grupo' => 'bigint unsigned', 'preco' => 'decimal(10,2)', 'imagem_path' => 'varchar(500)', 'disponivel' => 'tinyint(1)']);
    $exigir('complemento_insumos', ['id_complemento' => 'bigint unsigned', 'quantidade' => 'decimal(12,3)']);
    $exigir('produto_complemento_grupos', ['id_produto' => 'bigint unsigned', 'id_grupo' => 'bigint unsigned']);
    $exigir('pedido_item_complementos', ['id_pedido_item' => 'bigint unsigned', 'preco_unitario' => 'decimal(10,2)', 'total' => 'decimal(10,2)']);
    $exigir('pedido_itens', ['complementos_texto' => 'varchar(500)']);
    $exigir('ifood_catalogo_vinculos', ['tipo' => "enum('categoria','item','grupo','complemento')", 'ifood_id' => 'varchar(64)', 'enviado_preco' => 'decimal(10,2)']);
    $fks = (int) $db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN
        ('fk_complementos_grupo', 'fk_complemento_insumos_complemento', 'fk_complemento_insumos_insumo', 'fk_pcg_produto', 'fk_pcg_grupo', 'fk_pic_item', 'fk_pic_complemento')")->fetchColumn();
    if ($fks !== 7) {
        throw new RuntimeException('Ligacoes (FK) dos complementos ausentes.');
    }
    $checks = (int) $db->query("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN
        ('chk_complemento_grupos_limites', 'chk_complementos_preco', 'chk_pic_valores')")->fetchColumn();
    if ($checks !== 3) {
        throw new RuntimeException('Regras (CHECK) dos complementos ausentes.');
    }
    echo "Complementos confirmados: grupos, opcoes, fichas, ligacao com produtos, itens do pedido e cardapio do iFood.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao dos complementos: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
