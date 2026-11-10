<?php

declare(strict_types=1);

// Somente leitura: confirma as tabelas da aba Integracoes (integracoes e integracao_eventos).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-integracoes.php --verify\n");
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
    $i = $colunas('integracoes');
    if (($i['provedor'] ?? '') !== "enum('ifood','asaas','whatsapp')" || ($i['status'] ?? '') !== "enum('desconectado','pendente','conectado','erro')"
        || ($i['credenciais'] ?? '') !== 'text' || ($i['webhook_id'] ?? '') !== 'char(32)') {
        throw new RuntimeException('Colunas de integracoes incorretas.');
    }
    $e = $colunas('integracao_eventos');
    if (($e['payload'] ?? '') !== 'mediumtext' || !isset($e['id_externo'], $e['evento'], $e['recebido_em'])) {
        throw new RuntimeException('Colunas de integracao_eventos incorretas.');
    }
    $unicos = $db->query(<<<'SQL'
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
  AND INDEX_NAME IN ('uq_integracoes_restaurante_provedor', 'uq_integracoes_webhook', 'uq_integracao_eventos_externo')
GROUP BY INDEX_NAME
SQL)->fetchAll(PDO::FETCH_COLUMN);
    if (count($unicos) !== 3) {
        throw new RuntimeException('Indices unicos das integracoes ausentes.');
    }
    // Credenciais nunca em texto puro: tudo que existir precisa estar cifrado (prefixo v1:).
    $abertas = (int) $db->query("SELECT COUNT(*) FROM integracoes WHERE credenciais IS NOT NULL AND credenciais NOT LIKE 'v1:%'")->fetchColumn();
    if ($abertas !== 0) {
        throw new RuntimeException('Credenciais sem criptografia encontradas.');
    }
    echo "Integracoes confirmadas: tabelas, indices unicos e credenciais cifradas.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao das integracoes: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
