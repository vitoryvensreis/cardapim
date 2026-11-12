<?php

declare(strict_types=1);

// Somente leitura: confirma as tabelas do suporte (chamados e mensagens).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-suporte.php --verify\n");
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
    $c = $colunas('suporte_chamados');
    foreach (['id_restaurante' => 'bigint unsigned', 'assunto' => 'varchar(120)', 'status' => "enum('aberto','em_atendimento','resolvido')",
        'ultima_de' => "enum('restaurante','suporte')", 'ultima_mensagem_em' => 'datetime'] as $nome => $tipo) {
        if (($c[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna suporte_chamados.' . $nome . ' incorreta ou ausente.');
        }
    }
    $m = $colunas('suporte_mensagens');
    foreach (['id_chamado' => 'bigint unsigned', 'id_restaurante' => 'bigint unsigned', 'origem' => "enum('restaurante','suporte')", 'texto' => 'text'] as $nome => $tipo) {
        if (($m[$nome] ?? '') !== $tipo) {
            throw new RuntimeException('Coluna suporte_mensagens.' . $nome . ' incorreta ou ausente.');
        }
    }
    echo "Suporte confirmado: suporte_chamados (restaurante, situacao) e suporte_mensagens.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao do suporte: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
