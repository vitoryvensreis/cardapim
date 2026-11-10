<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--apply', '--verify'], true)) {
    fwrite(STDERR, "Uso: php8.4 database/seed-configuracoes-restaurante.php --apply|--verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $db = \Cardapim\Core\Database::connect();
    if ($argv[1] === '--apply') {
        $db->beginTransaction();
        try {
            $seed = require __DIR__ . '/seeders/configuracoes_restaurante_modelo.php';
            $seed($db);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    $expected = [
        'logo_colorida' => '/assets/img/logo-colorida.svg',
        'logo_branca' => '/assets/img/logo-branca.svg',
        'logo_preta' => '/assets/img/logo-preta.svg',
        'icone' => '/assets/img/icone.svg',
        'nome_sistema' => 'Cardapim',
        'descricao_padrao' => 'Gestão de restaurantes do jeito simples.',
        'texto_rodape' => 'Menos complicação. Mais restaurante.',
    ];

    $stmt = $db->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
    foreach ($expected as $key => $initialValue) {
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === '') {
            throw new RuntimeException("Configuracao publica ausente: $key");
        }
        // Este script valida a presenca e formato, sem impedir edicoes posteriores.
        if (str_starts_with($key, 'logo_') || $key === 'icone') {
            $valid = (str_starts_with($value, '/assets/img/') || str_starts_with($value, 'https://'));
            if (!$valid) {
                throw new RuntimeException("URL de imagem invalida: $key");
            }
        }
    }

    $restaurant = $db->prepare(<<<'SQL'
SELECT r.nome, r.status_rest, r.ultimo_pagamento, p.nome AS plano_nome, p.periodo
FROM restaurantes AS r
JOIN planos AS p ON p.id = r.plano_id
WHERE r.slug = ?
SQL);
    $restaurant->execute(['restaurante-modelo-cardapim']);
    $row = $restaurant->fetch();
    if ($row === false || $row['nome'] !== 'Restaurante Modelo Cardapim'
        || $row['status_rest'] !== 'ativo' || $row['ultimo_pagamento'] !== null
        || $row['plano_nome'] !== 'Demonstração Mensal' || $row['periodo'] !== 'mensal') {
        throw new RuntimeException('Restaurante modelo nao esta vinculado ao plano mensal.');
    }

    echo "Configuracoes e restaurante modelo vinculado ao plano mensal verificados.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha ao preparar configuracoes: ' . get_class($error) . "\n");
    exit(1);
}
