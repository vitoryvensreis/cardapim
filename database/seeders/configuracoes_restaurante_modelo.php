<?php

declare(strict_types=1);

// Nunca sobrescrever configuracoes personalizadas ou um restaurante ja existente.
return static function (PDO $db): void {
    $configs = [
        ['logo_colorida', '/assets/img/logo-colorida.svg', 'imagem', 'Logo oficial para fundo escuro'],
        ['logo_branca', '/assets/img/logo-branca.svg', 'imagem', 'Logo oficial branca'],
        ['logo_preta', '/assets/img/logo-preta.svg', 'imagem', 'Logo oficial para fundo claro'],
        ['icone', '/assets/img/icone.svg', 'imagem', 'Icone e favicon oficial'],
        ['nome_sistema', 'Deliveryzim', 'texto', 'Nome publico da plataforma'],
        ['descricao_padrao', 'Gestão de restaurantes do jeito simples.', 'texto', 'Descricao padrao de paginas'],
        ['texto_rodape', 'Menos complicação. Mais restaurante.', 'texto', 'Mensagem padrao no rodape'],
    ];

    $find = $db->prepare('SELECT id FROM configuracoes WHERE chave = ? LIMIT 1');
    $insert = $db->prepare(
        'INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, ?, ?)'
    );

    foreach ($configs as [$chave, $valor, $tipo, $descricao]) {
        $find->execute([$chave]);
        if ($find->fetchColumn() === false) {
            $insert->execute([$chave, $valor, $tipo, $descricao]);
        }
    }

    $plan = $db->prepare(
        'SELECT id FROM planos WHERE nome = ? AND periodo = ? AND ativo = 1 LIMIT 1'
    );
    $plan->execute(['Demonstração Mensal', 'mensal']);
    $planId = $plan->fetchColumn();
    if ($planId === false) {
        throw new RuntimeException('Plano Demonstracao Mensal nao encontrado.');
    }

    $slug = 'restaurante-modelo-cardapim';
    $lookup = $db->prepare('SELECT id FROM restaurantes WHERE slug = ? LIMIT 1');
    $lookup->execute([$slug]);
    if ($lookup->fetchColumn() === false) {
        $new = $db->prepare(
            'INSERT INTO restaurantes (nome, slug, plano_id, status_rest, ultimo_pagamento)
             VALUES (?, ?, ?, ?, NULL)'
        );
        $new->execute(['Restaurante Modelo Cardapim', $slug, $planId, 'ativo']);
        // Todo restaurante novo ja comeca com 5 mesas de 4 lugares (o caixa cria mais pelo "+").
        $idRestaurante = (int) $db->lastInsertId();
        $agora = date('Y-m-d H:i:s');
        $mesa = $db->prepare('INSERT INTO mesas (id_restaurante, numero, lugares, ativo, criado_em, atualizado_em) VALUES (?, ?, 4, 1, ?, ?)');
        for ($numero = 1; $numero <= 5; $numero++) {
            $mesa->execute([$idRestaurante, $numero, $agora, $agora]);
        }
    }
};
