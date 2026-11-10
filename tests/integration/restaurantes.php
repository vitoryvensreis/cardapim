<?php

declare(strict_types=1);

// Integracao (MySQL): CNPJ, catalogo paginado e disponibilidade. Nada e persistido.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Repositories\UsuarioRepository;
use Cardapim\Services\Catalogo;
use Cardapim\Services\Disponibilidade;

$db = Database::connect();
$db->beginTransaction();
$fail = static function (string $message): never {
    throw new RuntimeException($message);
};

try {
    $planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
    $insert = $db->prepare('INSERT INTO restaurantes (nome, slug, cnpj, plano_id, status_rest) VALUES (?, ?, ?, ?, ?)');

    // CNPJ: 14 digitos aceitos; formatado, duplicado ou curto recusados; NULL repetido permitido.
    $insert->execute(['CI Catalogo 00', 'ci-catalogo-00', '11222333000181', $planId, 'ativo']);
    foreach ([['11.222.333/0001-81', 'formatado'], ['11222333000181', 'duplicado'], ['1122233300018', 'curto']] as [$cnpj, $caso]) {
        try {
            $insert->execute(["CI CNPJ $caso", "ci-cnpj-$caso", $cnpj, $planId, 'ativo']);
            $fail("CNPJ $caso foi aceito.");
        } catch (PDOException $expected) {
            // 22001: maior que CHAR(14); HY000/3819: CHECK; 23000: duplicado.
            if (!in_array((string) $expected->getCode(), ['22001', '23000', 'HY000'], true)) {
                throw $expected;
            }
        }
    }
    $insert->execute(['CI Sem CNPJ A', 'ci-sem-cnpj-a', null, $planId, 'pendente']);
    $insert->execute(['CI Sem CNPJ B', 'ci-sem-cnpj-b', null, $planId, 'pendente']);

    // Catalogo: 14 ativos + inativos/suspensos que nao devem aparecer.
    for ($i = 1; $i <= 13; $i++) {
        $insert->execute([sprintf('CI Catalogo %02d', $i), sprintf('ci-catalogo-%02d', $i), null, $planId, 'ativo']);
    }
    $insert->execute(['CI Catalogo Suspenso', 'ci-catalogo-suspenso', null, $planId, 'suspenso']);
    $insert->execute(['CI Catalogo Inativo', 'ci-catalogo-inativo', null, $planId, 'inativo']);

    $catalogo = Catalogo::comBanco($db);
    $primeira = $catalogo->pagina(null, 'CI Catalogo');
    if (count($primeira['itens']) !== Catalogo::POR_PAGINA || $primeira['proximo_cursor'] === null) {
        $fail('Primeira pagina do catalogo incorreta.');
    }
    $segunda = $catalogo->pagina($primeira['proximo_cursor'], 'CI Catalogo');
    $nomes = array_column([...$primeira['itens'], ...$segunda['itens']], 'nome');
    if (count($nomes) !== 14 || $segunda['proximo_cursor'] !== null || count(array_unique($nomes)) !== 14) {
        $fail('Paginacao por cursor repetiu ou perdeu restaurantes.');
    }
    $ordenados = $nomes;
    sort($ordenados);
    if ($nomes !== $ordenados || in_array('CI Catalogo Suspenso', $nomes, true) || in_array('CI Catalogo Inativo', $nomes, true)) {
        $fail('Catalogo fora de ordem ou com restaurante nao ativo.');
    }
    if (array_diff(array_keys($primeira['itens'][0]), ['nome', 'slug', 'url', 'logo', 'iniciais']) !== []) {
        $fail('Catalogo expoe campos nao publicos.');
    }
    if (($catalogo->pagina(null, 'catalogo 07')['itens'][0]['slug'] ?? '') !== 'ci-catalogo-07') {
        $fail('Busca por nome falhou.');
    }
    if ($catalogo->pagina(null, '100%_')['itens'] !== []) {
        $fail('Curingas da busca nao foram escapados.');
    }
    try {
        $catalogo->pagina('cursor-adulterado', '');
        $fail('Cursor adulterado aceito.');
    } catch (InvalidArgumentException) {
    }

    $repo = new RestauranteRepository($db);
    if ($repo->buscarPublicoPorSlug('ci-catalogo-03') === null || $repo->buscarPublicoPorSlug('ci-catalogo-suspenso') !== null) {
        $fail('Pagina publica deve mostrar apenas restaurante ativo.');
    }

    // Disponibilidade
    $hash = password_hash('ci', PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO `user` (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $db->lastInsertId(), 'gerente', 'ci.disponivel', 'ci.disponivel@example.test', $hash, 'CI', 'Teste']);
    $disp = new Disponibilidade(new UsuarioRepository($db), $repo);
    $casos = [
        ['usuario', 'ci.disponivel', true, false],
        ['usuario', 'CI.Disponivel', true, false],
        ['usuario', 'ci.novo.usuario', true, true],
        ['usuario', 'ab', false, null],
        ['email', 'CI.DISPONIVEL@example.test', true, false],
        ['email', 'novo@example.test', true, true],
        ['email', 'sem-arroba', false, null],
        ['cnpj', '11.222.333/0001-81', true, false],
        ['cnpj', '64.069.224/0001-32', true, true],
        ['cnpj', '11.111.111/1111-11', false, null],
    ];
    foreach ($casos as [$campo, $valor, $valido, $disponivel]) {
        $r = $disp->verificar($campo, $valor);
        if ($r['valido'] !== $valido || $r['disponivel'] !== $disponivel || $r['mensagem'] === '') {
            $fail("Disponibilidade incorreta para $campo=$valor.");
        }
    }

    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

echo "CNPJ, catalogo paginado (somente ativos) e disponibilidade testados sem persistir dados.\n";
