<?php

declare(strict_types=1);

// Somente leitura: confirma o schema das abas do painel do gerente
// (exclusao/2FA em user, codigos de recuperacao, horarios e assinaturas).
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify') {
    fwrite(STDERR, "Uso: php8.4 database/verify-gerencia-abas.php --verify\n");
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
    $exigir = static function (bool $condicao, string $mensagem): void {
        if (!$condicao) {
            throw new RuntimeException($mensagem);
        }
    };

    $user = $colunas('user');
    $exigir(($user['dois_fatores'] ?? '') === "enum('desativado','app','email')", 'user.dois_fatores ausente ou com tipo errado.');
    $exigir(($user['dois_fatores_segredo'] ?? '') === 'varchar(255)', 'user.dois_fatores_segredo ausente.');
    $exigir(($user['dois_fatores_ativado_em'] ?? '') === 'datetime' && ($user['excluido_em'] ?? '') === 'datetime', 'user.dois_fatores_ativado_em/excluido_em ausentes.');
    $exigir((int) $db->query("SELECT COUNT(*) FROM `user` WHERE dois_fatores <> 'desativado' AND dois_fatores_ativado_em IS NULL")->fetchColumn() === 0, 'Contas com 2FA sem data de ativacao.');

    $codigos = $colunas('user_codigos_recuperacao');
    $exigir(($codigos['codigo_hash'] ?? '') === 'char(64)' && isset($codigos['id_user'], $codigos['usado_em']), 'user_codigos_recuperacao incorreta.');
    $fk = $db->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_codigos_recuperacao_user'")->fetchColumn();
    $exigir($fk === 'CASCADE', 'FK dos codigos de recuperacao sem ON DELETE CASCADE.');

    $rest = $colunas('restaurantes');
    $exigir(($rest['sempre_aberto'] ?? '') === 'tinyint(1)', 'restaurantes.sempre_aberto ausente.');
    $horarios = $colunas('restaurante_horarios');
    $exigir(($horarios['abre'] ?? '') === 'time' && ($horarios['fecha'] ?? '') === 'time' && isset($horarios['dia_semana'], $horarios['turno']), 'restaurante_horarios incorreta.');

    $assinaturas = $colunas('assinaturas');
    $exigir(($assinaturas['valor'] ?? '') === 'decimal(10,2)', 'assinaturas.valor deve ser DECIMAL(10,2).');
    $exigir(($assinaturas['status'] ?? '') === "enum('ativa','cancelada','encerrada')", 'assinaturas.status incorreto.');
    $semAssinatura = (int) $db->query(<<<'SQL'
SELECT COUNT(*) FROM restaurantes r
WHERE r.plano_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM assinaturas a WHERE a.id_restaurante = r.id)
SQL)->fetchColumn();
    $exigir($semAssinatura === 0, $semAssinatura . ' restaurante(s) com plano e sem assinatura.');
    $duplicadas = (int) $db->query("SELECT COUNT(*) FROM (SELECT id_restaurante FROM assinaturas WHERE status IN ('ativa','cancelada') GROUP BY id_restaurante HAVING COUNT(*) > 1) t")->fetchColumn();
    $exigir($duplicadas === 0, 'Restaurante com mais de uma assinatura vigente.');

    echo "Abas da gerencia confirmadas: 2FA/exclusao em user, codigos de recuperacao, horarios e assinaturas.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na verificacao das abas da gerencia: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
