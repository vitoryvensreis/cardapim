<?php

declare(strict_types=1);

// Conferencia read-only dos avisos da plataforma para as lojas (CI e deploy).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-avisos-loja.php --verify\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap/app.php';
try {
    $db = \Cardapim\Core\Database::connect();
    $esperado = [
        'avisos_loja' => ['id', 'titulo', 'texto', 'imagem_path', 'botao_texto', 'botao_destino', 'botao_url', 'inicio', 'fim', 'vezes_por_dia', 'ativo', 'criado_por', 'criado_em', 'atualizado_em'],
        'avisos_loja_exibicoes' => ['id_aviso', 'id_usuario', 'dia', 'id_restaurante', 'vezes', 'ultima_em', 'clicou_em'],
    ];
    foreach ($esperado as $tabela => $colunas) {
        $q = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$tabela]);
        $faltam = array_diff($colunas, $q->fetchAll(PDO::FETCH_COLUMN));
        if ($faltam !== []) {
            throw new RuntimeException("Faltam colunas em $tabela: " . implode(', ', $faltam));
        }
    }
    $q = $db->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'avisos_loja_exibicoes' AND INDEX_NAME = 'PRIMARY'");
    if ((string) $q->fetchColumn() !== 'id_aviso,id_usuario,dia') {
        throw new RuntimeException('Chave primaria de avisos_loja_exibicoes deve ser (id_aviso, id_usuario, dia).');
    }
    echo "Avisos das lojas confirmados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Avisos das lojas: falha de verificacao: ' . $e->getMessage() . "\n");
    exit(1);
}
