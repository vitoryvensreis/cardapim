<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-saudacoes.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $colunas = static function (string $tabela) use ($db): array {
        $q = $db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$tabela]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $config = $colunas('restaurante_whatsapp_saudacao');
    foreach (['id_restaurante' => 'bigint unsigned','ativo' => 'tinyint(1)','mensagem' => 'varchar(1500)'] as $campo => $tipo) {
        if (($config[$campo] ?? '') !== $tipo) throw new RuntimeException('Configuracao incompleta: ' . $campo);
    }
    $fila = $colunas('whatsapp_saudacao_diaria');
    foreach (['id_restaurante' => 'bigint unsigned','telefone' => 'varchar(48)','dia_local' => 'date','status' => "enum('pendente','enviando','enviado','falhou')"] as $campo => $tipo) {
        if (($fila[$campo] ?? '') !== $tipo) throw new RuntimeException('Fila incompleta: ' . $campo);
    }
    $q = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_saudacao_diaria' AND INDEX_NAME='uq_saudacao_restaurante_telefone_dia'");
    if ((int) $q->fetchColumn() !== 3) throw new RuntimeException('Falta UNIQUE restaurante + telefone + dia.');
    echo "Saudacoes WhatsApp: configuracao e controle diario confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Saudacoes: falha de verificacao: " . $e->getMessage() . "\n");
    exit(1);
}
