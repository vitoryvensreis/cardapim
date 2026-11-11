<?php

declare(strict_types=1);

// Somente leitura: confirma a fila de avisos de pedido pelo WhatsApp e a configuracao por restaurante.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-avisos.php --verify\n");
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
    $a = $colunas('pedido_avisos');
    foreach (['id_restaurante' => 'bigint unsigned', 'id_pedido' => 'bigint unsigned', 'evento' => 'varchar(20)', 'mensagem' => 'text',
        'status' => "enum('pendente','enviado','falhou','ignorado')", 'proxima_tentativa_em' => 'datetime'] as $nome => $tipo) {
        if (($a[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna pedido_avisos.' . $nome . ' incorreta ou ausente.');
        }
    }
    if (isset($a['telefone'])) {
        throw new RuntimeException('pedido_avisos nao deve copiar o telefone do cliente.');
    }
    $u = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_avisos' AND INDEX_NAME = 'uq_pedido_avisos_evento' AND NON_UNIQUE = 0")->fetchColumn();
    if ((int) $u !== 2) {
        throw new RuntimeException('Falta o UNIQUE (id_pedido, evento): o mesmo aviso poderia ir duas vezes.');
    }
    $c = $colunas('restaurante_avisos_whatsapp');
    if (($c['eventos'] ?? '') !== 'varchar(120)') {
        throw new RuntimeException('Tabela restaurante_avisos_whatsapp incorreta ou ausente.');
    }
    echo "Avisos confirmados: fila pedido_avisos (sem repetir) e restaurante_avisos_whatsapp.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao dos avisos: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
