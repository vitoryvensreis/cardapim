<?php

declare(strict_types=1);

// Apos tests/http/gerencia.sh: confere que cada cadastro, edicao e exclusao gerou log em log_user
// e que nenhuma senha ou codigo foi parar no log (somente banco cardapim_ci).
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'cardapim_ci') {
    fwrite(STDERR, "Recusado: somente no banco cardapim_ci.\n");
    exit(1);
}
$titulos = [
    'Insumo cadastrado', 'Insumo editado', 'Estoque reposto', 'Insumo excluído', 'Categoria cadastrada',
    'Produto cadastrado', 'Produto pausado', 'Produto excluído',
    'Equipe: conta criada', 'Equipe: acesso bloqueado', 'Equipe: acesso liberado', 'Equipe: senha redefinida', 'Equipe: conta excluída',
    'Perfil do restaurante editado', 'Horário de funcionamento alterado', 'Logo do restaurante alterada',
    'Assinatura cancelada', 'Cancelamento desfeito', 'Minha conta: dados alterados', 'Minha conta: senha alterada',
    'Verificação em duas etapas ligada', 'Verificação em duas etapas desligada', 'Verificação recusada',
];
$conta = $db->prepare('SELECT COUNT(*) FROM log_user l JOIN `user` u ON u.id = l.id_user WHERE u.usuario = ? AND l.titulo = ?');
foreach ($titulos as $titulo) {
    $conta->execute(['ci_gerente', $titulo]);
    if ((int) $conta->fetchColumn() < 1) {
        fwrite(STDERR, "Log ausente: ci_gerente / $titulo\n");
        exit(1);
    }
}
$assinou = (int) $db->query("SELECT COUNT(*) FROM log_user WHERE titulo IN ('Assinatura contratada', 'Plano trocado', 'Cancelamento desfeito')")->fetchColumn();
$verificado = (int) $db->query("SELECT COUNT(*) FROM log_user WHERE titulo = 'Login' AND descricao LIKE '%Verificado pelo aplicativo%'")->fetchColumn();
$recuperacao = (int) $db->query("SELECT COUNT(*) FROM log_user WHERE titulo = 'Login' AND descricao LIKE '%código de recuperação%'")->fetchColumn();
$email = (int) $db->query("SELECT COUNT(*) FROM log_user WHERE titulo = 'Login' AND descricao LIKE '%código no e-mail%'")->fetchColumn();
$vazou = (int) $db->query("SELECT COUNT(*) FROM log_user WHERE descricao LIKE '%Mesa-sol-1234%' OR descricao LIKE '%Nova-senha-777%' OR descricao LIKE '%Senha#CI2099%'")->fetchColumn();
if ($assinou < 1 || $verificado < 1 || $recuperacao < 1 || $email < 1 || $vazou !== 0) {
    fwrite(STDERR, "Logs de assinatura/2FA incompletos ou senha registrada no log.\n");
    exit(1);
}
echo "Logs das abas da gerencia confirmados (" . count($titulos) . " tipos), sem senhas registradas.\n";
