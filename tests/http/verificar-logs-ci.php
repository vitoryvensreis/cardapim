<?php

declare(strict_types=1);

// Apos tests/http/auth.sh: confere os registros de login em log_user (somente banco cardapim_ci).
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: somente no banco cardapim_ci.\n");
    exit(1);
}
$conta = static function (string $usuario, string $titulo, string $trecho) use ($db): int {
    $q = $db->prepare('SELECT COUNT(*) FROM log_user l JOIN `user` u ON u.id = l.id_user WHERE u.usuario = ? AND l.titulo = ? AND l.descricao LIKE ?');
    $q->execute([$usuario, $titulo, '%' . $trecho . '%']);
    return (int) $q->fetchColumn();
};
$regras = [
    ['ci_gerente', 'Login', 'Manter conectado: sim'],
    ['ci_gerente', 'Login', 'Manter conectado: não'],
    ['ci_caixa', 'Login', 'por e-mail'],
    ['ci_gerente', 'Login automático', 'manter conectado'],
    ['ci_gerente', 'Alerta de segurança', 'possível cópia'],
    ['ci_gerente', 'Login recusado', 'Senha incorreta'],
    ['ci_caixa', 'Logout', 'Saiu da conta'],
];
foreach ($regras as [$usuario, $titulo, $trecho]) {
    if ($conta($usuario, $titulo, $trecho) < 1) {
        fwrite(STDERR, "Log ausente: $usuario / $titulo / $trecho\n");
        exit(1);
    }
}
$ultimo = $db->query("SELECT ultimo_acesso FROM `user` WHERE usuario = 'ci_gerente'")->fetchColumn();
if ($ultimo === null || $ultimo === false) {
    fwrite(STDERR, "ultimo_acesso nao atualizado.\n");
    exit(1);
}
echo "Logs de login, login automatico, alerta, recusa e logout registrados em log_user.\n";
