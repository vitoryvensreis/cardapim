<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;

$db = Database::connect();
$db->beginTransaction();

try {
    $planId = (int) $db->query("SELECT id FROM planos ORDER BY id LIMIT 1")->fetchColumn();
    if ($planId < 1) {
        throw new RuntimeException('Um plano e necessario para o restaurante de teste.');
    }

    $restaurant = $db->prepare(
        'INSERT INTO restaurantes (nome, slug, plano_id, status_rest) VALUES (?, ?, ?, ?)'
    );
    $restaurant->execute(['Restaurante de integracao de usuarios', 'user-teste-ci', $planId, 'ativo']);
    $restaurantId = (int) $db->lastInsertId();

    $hash = password_hash('senha-testada-sem-uso-real', PASSWORD_DEFAULT);
    if (!password_verify('senha-testada-sem-uso-real', $hash)) {
        throw new RuntimeException('Hash de senha invalido.');
    }

    $insert = $db->prepare(<<<'SQL'
INSERT INTO `user`
    (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome, whatsapp,
     ultimo_codigo_hash, ultimo_codigo_expira_em)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
    $insert->execute([
        $restaurantId, 'gerente', 'gerente-ci', 'gerente-ci@example.test',
        $hash, 'Gerente', 'Teste', '5585999999999',
        password_hash('123456', PASSWORD_DEFAULT), '2026-11-10 23:00:00',
    ]);
    $gerenteId = (int) $db->lastInsertId();

    $read = $db->prepare('SELECT id_restaurante, cargo, ativo, ultimo_acesso, criado_em FROM `user` WHERE id = ?');
    $read->execute([$gerenteId]);
    $saved = $read->fetch();
    if (!$saved || (int) $saved['id_restaurante'] !== $restaurantId
        || $saved['cargo'] !== 'gerente' || (int) $saved['ativo'] !== 1
        || $saved['ultimo_acesso'] !== null || $saved['criado_em'] === null) {
        throw new RuntimeException('Gerente salvo de forma incorreta.');
    }

    foreach (['cliente', 'motoqueiro', 'garcom', 'caixa'] as $cargo) {
        $insert->execute([
            $restaurantId, $cargo, "$cargo-ci", "$cargo-ci@example.test",
            $hash, ucfirst($cargo), 'Teste', null, null, null,
        ]);
    }

    $insert->execute([
        null, 'adm_master', 'master-ci', 'master-ci@example.test',
        $hash, 'Admin', 'Master', null, null, null,
    ]);
    $masterId = (int) $db->lastInsertId();

    $log = $db->prepare('INSERT INTO log_user (titulo, descricao, id_user, ip) VALUES (?, ?, ?, ?)');
    $log->execute(['Login de teste', 'Log associado a gerente', $gerenteId, '2001:db8::1']);
    $logId = (int) $db->lastInsertId();
    $checkLog = $db->prepare('SELECT id_user FROM log_user WHERE id = ?');
    $checkLog->execute([$logId]);
    if ((int) $checkLog->fetchColumn() !== $gerenteId) {
        throw new RuntimeException('Log sem vinculo com usuario.');
    }

    $invalid = [
        [null, 'gerente', 'sem-restaurante-ci', 'sem-restaurante-ci@example.test'],
        [$restaurantId, 'adm_master', 'master-com-rest-ci', 'master-com-rest-ci@example.test'],
        [999999999, 'caixa', 'rest-invalido-ci', 'rest-invalido-ci@example.test'],
    ];
    foreach ($invalid as [$tenantId, $role, $username, $email]) {
        try {
            $insert->execute([$tenantId, $role, $username, $email, $hash, 'Teste', 'Invalido', null, null, null]);
            throw new RuntimeException('Insercao com escopo indevido foi aceita.');
        } catch (PDOException $expected) {
            if (!in_array((string) $expected->getCode(), ['23000', 'HY000'], true)) {
                throw $expected;
            }
        }
    }

    try {
        $log->execute(['Log invalido', 'Usuario inexistente', 999999999, null]);
        throw new RuntimeException('log_user permitiu FK de usuario inexistente.');
    } catch (PDOException $expected) {
        if ((string) $expected->getCode() !== '23000') {
            throw $expected;
        }
    }

    try {
        $db->prepare('DELETE FROM `user` WHERE id = ?')->execute([$gerenteId]);
        throw new RuntimeException('Exclusao de usuario com logs foi permitida.');
    } catch (PDOException $expected) {
        if ((string) $expected->getCode() !== '23000') {
            throw $expected;
        }
    }

    $db->prepare('UPDATE `user` SET ativo = 0 WHERE id = ?')->execute([$gerenteId]);
    $checkLog->execute([$logId]);
    if ((int) $checkLog->fetchColumn() !== $gerenteId || $masterId < 1) {
        throw new RuntimeException('Desativar conta afetou o historico dos logs.');
    }

    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

echo "Cargos, master, isolamento, hashes e vinculo dos logs testados sem persistir dados.\n";
