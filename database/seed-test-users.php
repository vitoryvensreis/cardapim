<?php

declare(strict_types=1);

// Seed de testes para CLI: senhas geradas localmente e gravadas somente em arquivo
// privado fora de public/, sem jamais aparecer em Git, stdout ou logs do deploy.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--apply', '--verify'], true)) {
    fwrite(STDERR, "Uso: php8.4 database/seed-test-users.php --apply|--verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;

$accounts = [
    ['teste_cliente', 'cliente', 'Cliente', 'Teste', 'cliente.teste@cardapim.test'],
    ['teste_gerente', 'gerente', 'Gerente', 'Teste', 'gerente.teste@cardapim.test'],
    ['teste_motoqueiro', 'motoqueiro', 'Motoqueiro', 'Teste', 'motoqueiro.teste@cardapim.test'],
    ['teste_garcom', 'garcom', 'Garçom', 'Teste', 'garcom.teste@cardapim.test'],
    ['teste_caixa', 'caixa', 'Caixa', 'Teste', 'caixa.teste@cardapim.test'],
    ['teste_adm_master', 'adm_master', 'Administrador', 'Teste', 'master.teste@cardapim.test'],
];

// Numero privado: configurar CARDAPIM_TEST_WHATSAPP no shared/.env da VPS.
// Nunca embutir dados pessoais de contato em um commit publico.
$envFile = dirname(__DIR__) . '/.env';
$env = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : [];
$whatsapp = getenv('CARDAPIM_TEST_WHATSAPP');
if ($whatsapp === false) {
    $whatsapp = is_array($env) ? (string) ($env['CARDAPIM_TEST_WHATSAPP'] ?? '') : '';
}
$whatsapp = preg_replace('/\D+/', '', (string) $whatsapp);
if ($whatsapp === null || strlen($whatsapp) !== 11) {
    fwrite(STDERR, "Configure CARDAPIM_TEST_WHATSAPP com DDD e 11 digitos no .env privado.\n");
    exit(1);
}
$mode = $argv[1];
$filename = 'acessos-cardapim-testes.txt';
$path = getenv('CI') === 'true'
    ? sys_get_temp_dir() . '/cardapim-ci-' . $filename
    : dirname(__DIR__, 3) . '/shared/backups/' . $filename;

try {
    $db = Database::connect();
    $databaseName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $isCI = getenv('CI') === 'true' && $databaseName === 'cardapim_ci';

    if ($databaseName !== 'cardapim' && !$isCI) {
        throw new RuntimeException('Banco inesperado; seed cancelado.');
    }

    $findRestaurant = $db->prepare(<<<'SQL'
SELECT r.id
FROM restaurantes r
JOIN planos p ON r.plano_id = p.id
WHERE r.slug = ? AND r.status_rest = 'ativo'
  AND p.nome = ? AND p.periodo = 'mensal'
LIMIT 1
SQL);
    $findRestaurant->execute(['restaurante-modelo-cardapim', 'Demonstração Mensal']);
    $restaurantId = $findRestaurant->fetchColumn();
    if ($restaurantId === false) {
        throw new RuntimeException('Restaurante modelo e plano mensal indisponiveis.');
    }

    $select = $db->prepare('SELECT id, id_restaurante, cargo, email, whatsapp, ativo, senha_hash FROM `user` WHERE usuario = ?');
    $existing = [];
    foreach ($accounts as [$username]) {
        $select->execute([$username]);
        $existing[$username] = $select->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $numberExisting = count(array_filter($existing));

    if ($mode === '--apply' && $numberExisting === 0) {
        // Nao criar uma segunda conta master global em banco com usuarios master reais.
        if ((int) $db->query("SELECT COUNT(*) FROM `user` WHERE cargo = 'adm_master'")->fetchColumn() !== 0) {
            throw new RuntimeException('Ja existe adm_master; seed de master de teste bloqueado.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Diretorio privado de credenciais indisponivel.');
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Arquivo de credenciais ja existe; nao sobrescrever.');
        }

        $passwords = [];
        foreach ($accounts as [$username]) {
            $passwords[$username] = bin2hex(random_bytes(24));
        }

        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Nao foi possivel abrir o arquivo de credenciais.');
        }

        try {
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Permissao segura de credenciais nao configurada.');
            }
            $lines = [
                'CARDAPIM — CONTAS DE TESTE',
                'As senhas abaixo sao privadas. Nao encaminhe por canais publicos.',
                'Login ainda nao foi implementado: estes dados nao autenticam no site.',
                '',
            ];
            foreach ($accounts as [$username, $role, , , $email]) {
                $lines[] = 'Cargo: ' . $role;
                $lines[] = 'Usuario: ' . $username;
                $lines[] = 'Email: ' . $email;
                $lines[] = 'Senha: ' . $passwords[$username];
                $lines[] = 'WhatsApp: ' . $whatsapp;
                $lines[] = 'Ativo: ' . ($role === 'adm_master' ? 'nao (master bloqueado ate a configuracao do login)' : 'sim');
                $lines[] = '';
            }
            $content = implode("\n", $lines) . "\n";
            if (fwrite($file, $content) !== strlen($content) || !fflush($file)) {
                throw new RuntimeException('Nao foi possivel gravar credenciais completas.');
            }
        } catch (Throwable $error) {
            fclose($file);
            unlink($path);
            throw $error;
        }
        fclose($file);

        try {
            $db->beginTransaction();
            $insert = $db->prepare(<<<'SQL'
INSERT INTO `user`
    (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome, whatsapp, ativo)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

            foreach ($accounts as [$username, $role, $firstName, $lastName, $email]) {
                $idRestaurant = $role === 'adm_master' ? null : (int) $restaurantId;
                $active = $role === 'adm_master' ? 0 : 1;
                $insert->execute([
                    $idRestaurant, $role, $username, $email,
                    password_hash($passwords[$username], PASSWORD_DEFAULT),
                    $firstName, $lastName, $whatsapp, $active,
                ]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            unlink($path);
            throw $error;
        }
        unset($passwords, $content, $lines);
    } elseif ($mode === '--apply' && $numberExisting !== count($accounts)) {
        throw new RuntimeException('Usuarios de teste parcialmente existentes; requer revisao manual.');
    }

    $count = 0;
    foreach ($accounts as [$username, $role, , , $email]) {
        $select->execute([$username]);
        $found = $select->fetch(PDO::FETCH_ASSOC);
        $expectedRestaurant = $role === 'adm_master' ? null : (int) $restaurantId;
        $expectedActive = $role === 'adm_master' ? 0 : 1;
        if (!$found || $found['cargo'] !== $role || $found['email'] !== $email
            || $found['whatsapp'] !== $whatsapp
            || ($found['id_restaurante'] === null ? null : (int) $found['id_restaurante']) !== $expectedRestaurant
            || (int) $found['ativo'] !== $expectedActive
            || password_get_info($found['senha_hash'])['algoName'] === 'unknown') {
            throw new RuntimeException('Uma conta de teste nao corresponde ao esperado.');
        }
        ++$count;
    }

    if ($mode === '--apply' && (!is_file($path) || (fileperms($path) & 077) !== 0)) {
        throw new RuntimeException('Arquivo privado nao encontrado ou com permissoes incorretas.');
    }

    echo "Contas de teste validadas: $count. Master desativado. Nenhuma senha exibida.\n";
    if ($mode === '--apply') {
        echo "Credenciais armazenadas em arquivo privado fora da web.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha no seed de teste: ' . get_class($error) . "\n");
    exit(1);
}
