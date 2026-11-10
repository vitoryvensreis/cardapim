<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Repositories\PlanoRepository;

$db = Database::connect();
$repository = new PlanoRepository($db);

// Estes tres planos sao semeados pelo proprio CI antes do teste.
$before = $repository->listarPublicos();
$names = array_column($before, 'nome');
foreach (['Demonstração Mensal', 'Demonstração Semestral', 'Demonstração Anual'] as $name) {
    if (!in_array($name, $names, true)) {
        throw new RuntimeException("Plano publico ausente: $name");
    }
}
$periods = array_column($before, 'periodo');
if (array_slice($periods, 0, 3) !== ['mensal', 'semestral', 'anual']) {
    throw new RuntimeException('Ordem de apresentacao dos periodos incorreta.');
}

$db->beginTransaction();
try {
    $find = $db->prepare('SELECT id FROM planos WHERE nome = ? AND periodo = ?');
    $find->execute(['Demonstração Mensal', 'mensal']);
    $monthlyId = (int) $find->fetchColumn();
    $find->execute(['Demonstração Semestral', 'semestral']);
    $semiannualId = (int) $find->fetchColumn();

    if ($monthlyId <= 0 || $semiannualId <= 0) {
        throw new RuntimeException('IDs de plano invalidos.');
    }

    $db->prepare('UPDATE planos SET visibilidade = 0 WHERE id = ?')->execute([$monthlyId]);
    $db->prepare('UPDATE planos SET ativo = 0 WHERE id = ?')->execute([$semiannualId]);

    $filtered = $repository->listarPublicos();
    $filteredIds = array_map('intval', array_column($filtered, 'id'));
    if (in_array($monthlyId, $filteredIds, true) || in_array($semiannualId, $filteredIds, true)) {
        throw new RuntimeException('Planos ocultos ou inativos nao podem aparecer publicamente.');
    }
    if (count($filtered) !== count($before) - 2) {
        throw new RuntimeException('Quantidade de planos filtrados inconsistente.');
    }
} finally {
    $db->rollBack();
}

echo "Planos ativos/visiveis, ordem e filtros verificados sem alterar dados.\n";
