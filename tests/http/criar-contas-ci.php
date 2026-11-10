<?php

declare(strict_types=1);

// Cria contas ci_* com senha conhecida para tests/http/auth.sh.
// SOMENTE no banco de CI (cardapim_ci): recusa qualquer outro banco.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: contas de teste so podem ser criadas no banco cardapim_ci.\n");
    exit(1);
}

$planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
$restaurante = static function (string $nome, string $slug, string $status) use ($db, $planId): int {
    $find = $db->prepare('SELECT id FROM restaurantes WHERE slug = ?');
    $find->execute([$slug]);
    $id = $find->fetchColumn();
    if ($id === false) {
        $db->prepare('INSERT INTO restaurantes (nome, slug, plano_id, status_rest) VALUES (?, ?, ?, ?)')->execute([$nome, $slug, $planId, $status]);
        $id = $db->lastInsertId();
    }
    return (int) $id;
};
$modelo = $restaurante('Restaurante Modelo Cardapim', 'restaurante-modelo-cardapim', 'ativo');
$outro = $restaurante('CI Outro Restaurante', 'ci-outro-restaurante', 'ativo');
$suspenso = $restaurante('CI Restaurante Suspenso', 'ci-restaurante-suspenso', 'suspenso');

$hash = password_hash('Senha#CI2026', PASSWORD_DEFAULT);
$insert = $db->prepare('INSERT IGNORE INTO `user` (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [$modelo, 'gerente', 'ci_gerente', 1],
    [$modelo, 'caixa', 'ci_caixa', 1],
    [$modelo, 'motoqueiro', 'ci_motoqueiro', 1],
    [$modelo, 'garcom', 'ci_garcom', 1],
    [$modelo, 'gerente', 'ci_inativo', 0],
    [$outro, 'gerente', 'ci_outro_gerente', 1],
    [$suspenso, 'gerente', 'ci_suspenso', 1],
    [null, 'adm_master', 'ci_master', 1],
] as [$rest, $cargo, $usuario, $ativo]) {
    $insert->execute([$rest, $cargo, $usuario, str_replace('_', '.', $usuario) . '@example.test', $hash, 'CI', ucfirst($cargo), $ativo]);
}
echo "Contas ci_* prontas no banco de CI.\n";
