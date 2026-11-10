<?php

declare(strict_types=1);

// Integracao (MySQL): regras de endereco em restaurantes. Nada e persistido.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$db = \Cardapim\Core\Database::connect();
$db->beginTransaction();
try {
    $planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
    $insert = $db->prepare(
        'INSERT INTO restaurantes (nome, slug, plano_id, cep, logradouro, numero, bairro, cidade, estado, latitude, longitude)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute(['CI Endereco OK', 'ci-endereco-ok', $planId, '60110000', 'Av. Exemplo', 'S/N', 'Aldeota', 'Fortaleza', 'CE', '-3.7300000', '-38.5000000']);
    $insert->execute(['CI Sem Endereco', 'ci-sem-endereco', $planId, null, null, null, null, null, null, null, null]);

    $invalidos = [
        ['cep com traco', '60110-000', 'CE', '-3.7', '-38.5'],
        ['cep curto', '6011000', 'CE', '-3.7', '-38.5'],
        ['UF inexistente', '60110000', 'XX', '-3.7', '-38.5'],
        ['UF minuscula', '60110000', 'ce', '-3.7', '-38.5'],
        ['latitude fora do mundo', '60110000', 'CE', '-91', '-38.5'],
        ['so latitude', '60110000', 'CE', '-3.7', null],
    ];
    foreach ($invalidos as $i => [$caso, $cep, $uf, $lat, $lon]) {
        try {
            $insert->execute(["CI Invalido $i", "ci-invalido-$i", $planId, $cep, 'Rua', '1', 'Centro', 'Fortaleza', $uf, $lat, $lon]);
            throw new RuntimeException("Banco aceitou endereco invalido: $caso.");
        } catch (PDOException) {
        }
    }
    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
echo "Endereco: CEP com 8 digitos, UF valida e coordenadas testados sem persistir dados.\n";
