<?php

declare(strict_types=1);

// Valores ilustrativos, sem cobranca real.
// Nao atualizar um plano existente com mesmo nome/periodo.
return static function (PDO $db): void {
    $planos = [
        ['Demonstração Mensal', 'Plano fictício para testes — cobrança mensal.', '99.90', 'mensal'],
        ['Demonstração Semestral', 'Plano fictício para testes — cobrança a cada seis meses.', '499.90', 'semestral'],
        ['Demonstração Anual', 'Plano fictício para testes — cobrança anual.', '899.90', 'anual'],
    ];

    $find = $db->prepare('SELECT id FROM planos WHERE nome = ? AND periodo = ? LIMIT 1');
    $insert = $db->prepare('INSERT INTO planos (nome, descricao, valor, periodo, ativo) VALUES (?, ?, ?, ?, 1)');

    foreach ($planos as [$nome, $descricao, $valor, $periodo]) {
        $find->execute([$nome, $periodo]);
        if ($find->fetchColumn() !== false) {
            continue;
        }
        $insert->execute([$nome, $descricao, $valor, $periodo]);
    }
};
