<?php

declare(strict_types=1);

// Somente leitura: confirma o schema da integracao com o iFood (pedidos, cardapio ligado e fila de acoes).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-ifood.php --verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    $colunas = static function (string $tabela) use ($db): array {
        $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabela]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $exigir = static function (string $tabela, array $esperado) use ($colunas): void {
        $c = $colunas($tabela);
        foreach ($esperado as $nome => $tipo) {
            if (($c[$nome] ?? '') !== $tipo) {
                throw new RuntimeException('Coluna ' . $tabela . '.' . $nome . ' incorreta ou ausente.');
            }
        }
    };
    $exigir('pedidos', [
        'forma_pagamento' => "enum('pix_online','pix_entrega','debito','credito','dinheiro','misto','ifood')",
        'ifood_id' => 'varchar(64)', 'ifood_display_id' => 'varchar(20)', 'ifood_entregue_por' => "enum('ifood','loja')",
        'ifood_codigo_coleta' => 'varchar(20)', 'ifood_dados' => 'mediumtext',
    ]);
    $exigir('pedido_pagamentos', ['forma' => "enum('dinheiro','debito','credito','pix','pix_online','ifood')"]);
    $exigir('pedido_itens', ['observacao' => 'varchar(400)']);
    $exigir('integracao_eventos', ['tentativas' => 'smallint unsigned', 'erro' => 'varchar(255)']);
    $exigir('ifood_itens', ['ifood_item_id' => 'varchar(64)', 'id_produto' => 'bigint unsigned', 'preco' => 'decimal(10,2)', 'vinculo' => "enum('automatico','manual')"]);
    $exigir('ifood_acoes', ['acao' => 'varchar(30)', 'status' => "enum('pendente','feito','falhou')", 'proxima_em' => 'datetime']);
    $u = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'uq_pedidos_ifood' AND NON_UNIQUE = 0")->fetchColumn();
    if ((int) $u !== 2) {
        throw new RuntimeException('Falta o UNIQUE (id_restaurante, ifood_id): o mesmo pedido do iFood poderia entrar duas vezes.');
    }
    echo "iFood confirmado: pedidos (ifood_*), pagamento 'ifood', ifood_itens e fila ifood_acoes.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do iFood: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
