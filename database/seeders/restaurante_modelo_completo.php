<?php

declare(strict_types=1);

/**
 * Completa o cadastro do Restaurante Modelo Cardapim (demonstracao).
 * - E-mail: o do gerente vinculado ao restaurante (lido do banco, nunca inventado).
 * - Demais dados: ficticios e identificados como exemplo.
 * - Preenche somente campos vazios: nunca sobrescreve dados ja informados.
 */
return static function (PDO $db): void {
    $slug = 'restaurante-modelo-cardapim';
    $find = $db->prepare('SELECT id FROM restaurantes WHERE slug = ? FOR UPDATE');
    $find->execute([$slug]);
    $id = $find->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('Restaurante Modelo nao encontrado.');
    }

    $gerente = $db->prepare(
        "SELECT email FROM `user` WHERE id_restaurante = ? AND cargo = 'gerente'
         ORDER BY ativo DESC, id ASC LIMIT 1"
    );
    $gerente->execute([(int) $id]);
    $email = $gerente->fetchColumn();
    if ($email === false || $email === '') {
        throw new RuntimeException('Nenhum gerente vinculado ao Restaurante Modelo para usar o e-mail.');
    }

    $dados = [
        'email' => (string) $email,
        'telefone' => '85900000000',               // ficticio: (85) 90000-0000
        'cnpj' => '12345678000195',                // ficticio de exemplo (digitos validos)
        'cep' => '60000000',
        'logradouro' => 'Rua do Restaurante Modelo',
        'numero' => '100',
        'complemento' => 'Loja 1',
        'bairro' => 'Centro',
        'cidade' => 'Fortaleza',
        'estado' => 'CE',
        'ponto_referencia' => 'Endereço fictício para demonstração',
        'logo_path' => '/assets/img/icone.svg',
        'ultimo_pagamento' => '2026-11-10',
    ];

    // Coluna a coluna, somente quando estiver vazia. Numero e data comparam so com NULL
    // (comparar com '' gera erro no modo estrito do MySQL).
    $semTexto = ['ultimo_pagamento'];
    foreach ($dados as $coluna => $valor) {
        $vazio = in_array($coluna, $semTexto, true) ? '%1$s IS NULL' : "(%1\$s IS NULL OR %1\$s = '')";
        // Nomes de coluna fixos deste arquivo, nunca vindos de entrada externa.
        $sql = sprintf('UPDATE restaurantes SET %1$s = ? WHERE id = ? AND ' . $vazio, $coluna);
        $db->prepare($sql)->execute([$valor, (int) $id]);
    }

    // Coordenadas sempre juntas (CHECK exige as duas): centro de Fortaleza, aproximado.
    $db->prepare(
        'UPDATE restaurantes SET latitude = ?, longitude = ? WHERE id = ? AND latitude IS NULL AND longitude IS NULL'
    )->execute(['-3.7319000', '-38.5267000', (int) $id]);
};
