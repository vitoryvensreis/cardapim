<?php

declare(strict_types=1);

// Somente leitura: confirma as tabelas do Pix na mesa (painel do garcom).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-garcom.php --verify\n");
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
    $p = $colunas('comanda_pix');
    foreach (['id_restaurante' => 'bigint unsigned', 'id_pedido' => 'bigint unsigned', 'id_caixa' => 'bigint unsigned', 'cobranca_id' => 'varchar(60)',
        'valor' => 'decimal(10,2)', 'copia_cola' => 'varchar(1000)', 'status' => "enum('pendente','pago','cancelado')", 'expira_em' => 'datetime'] as $nome => $tipo) {
        if (($p[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna comanda_pix.' . $nome . ' incorreta ou ausente.');
        }
    }
    $u = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comanda_pix' AND INDEX_NAME = 'uq_comanda_pix_cobranca' AND NON_UNIQUE = 0")->fetchColumn();
    if ((int) $u !== 1) {
        throw new RuntimeException('Falta o UNIQUE da cobranca: o mesmo Pix poderia entrar duas vezes no caixa.');
    }
    $s = $colunas('asaas_clientes_salao');
    if (($s['id_externo'] ?? '') !== 'varchar(60)') {
        throw new RuntimeException('Tabela asaas_clientes_salao incorreta ou ausente.');
    }
    echo "Garcom confirmado: comanda_pix (Pix na mesa, sem repetir) e asaas_clientes_salao.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do painel do garcom: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
