<?php

declare(strict_types=1);

// Completa (--apply) e confere (--verify) o cadastro do Restaurante Modelo. Idempotente.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--apply', '--verify'], true)) {
    fwrite(STDERR, "Uso: php8.4 database/seed-restaurante-modelo.php --apply|--verify\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Shared\Validation\Documento;

try {
    $db = \Cardapim\Core\Database::connect();
    if ($argv[1] === '--apply') {
        $db->beginTransaction();
        try {
            (require __DIR__ . '/seeders/restaurante_modelo_completo.php')($db);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    $row = $db->query(<<<'SQL'
SELECT r.*, (
    SELECT u.email FROM `user` u WHERE u.id_restaurante = r.id AND u.cargo = 'gerente'
    ORDER BY u.ativo DESC, u.id ASC LIMIT 1
) AS email_gerente
FROM restaurantes r WHERE r.slug = 'restaurante-modelo-cardapim'
SQL)->fetch();
    if (!$row) {
        throw new RuntimeException('Restaurante Modelo nao encontrado.');
    }
    $obrigatorios = ['nome', 'slug', 'telefone', 'email', 'cnpj', 'plano_id', 'cep', 'logradouro', 'numero',
        'bairro', 'cidade', 'estado', 'latitude', 'longitude', 'logo_path', 'ultimo_pagamento'];
    foreach ($obrigatorios as $coluna) {
        if ($row[$coluna] === null || $row[$coluna] === '') {
            throw new RuntimeException("Campo $coluna do Restaurante Modelo vazio.");
        }
    }
    if (strcasecmp((string) $row['email'], (string) $row['email_gerente']) !== 0) {
        throw new RuntimeException('E-mail do restaurante diferente do e-mail do gerente.');
    }
    if (!Documento::cnpjValido((string) $row['cnpj']) || $row['status_rest'] !== 'ativo') {
        throw new RuntimeException('CNPJ ou status do Restaurante Modelo invalido.');
    }
    echo "Restaurante Modelo completo: contato, CNPJ, endereco e coordenadas preenchidos.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha no Restaurante Modelo: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
