<?php

declare(strict_types=1);

/**
 * Cardapio de exemplo do Restaurante Modelo Cardapim (demonstracao).
 * Idempotente: so cria o que ainda nao existe (por nome) e nunca altera estoque, preco ou
 * ficha tecnica que o restaurante ja tenha editado.
 *
 * Quantidades na unidade do insumo: kg (0.080 = 80 g), l (0.020 = 20 ml) ou un.
 * Custos aproximados de mercado, apenas para demonstrar o calculo de custo por produto.
 */
return static function (PDO $db): array {
    $categorias = [
        // nome => [descricao, ordem]
        'Lanches' => ['Hambúrgueres artesanais', 1],
        'Porções' => ['Para dividir com a galera', 2],
        'Espetinhos' => ['Na brasa, na hora', 3],
        'Bebidas' => ['Geladas', 4],
    ];

    $insumos = [
        // nome => [unidade, estoque_atual, estoque_minimo, custo_unitario]
        'Pão de hambúrguer' => ['un', '50', '20', '1.2000'],
        'Carne moída para hambúrguer' => ['kg', '5.000', '2.000', '38.9000'],
        'Queijo muçarela (fatia)' => ['un', '100', '30', '0.4500'],
        'Alface americana' => ['kg', '1.000', '0.300', '12.0000'],
        'Tomate' => ['kg', '2.000', '0.500', '8.5000'],
        'Molho especial da casa' => ['kg', '2.000', '0.500', '18.0000'],
        'Batata palito congelada' => ['kg', '10.000', '3.000', '22.0000'],
        'Óleo de soja' => ['l', '9.000', '3.000', '8.9000'],
        'Sal refinado' => ['kg', '1.000', '0.200', '3.0000'],
        'Embalagem de batata média' => ['un', '100', '30', '0.6000'],
        'Embalagem de batata grande' => ['un', '100', '30', '0.8500'],
        'Carne bovina em cubos (espeto)' => ['kg', '4.000', '1.500', '42.9000'],
        'Espeto de bambu' => ['un', '200', '50', '0.0500'],
        'Sal grosso' => ['kg', '1.000', '0.200', '4.0000'],
        'Espetinho de frango pronto' => ['un', '30', '10', '3.5000'],
        'Cerveja lata 350 ml' => ['un', '48', '24', '3.8000'],
        'Refrigerante lata 350 ml' => ['un', '48', '24', '2.9000'],
    ];

    $produtos = [
        // nome => [categoria, descricao, preco, codigo, ordem, ficha [insumo => quantidade por unidade]]
        'X-Burguer' => ['Lanches', 'Pão, hambúrguer de 80 g, duas fatias de queijo, alface, tomate e molho especial.', '24.90', 'HB01', 1, [
            'Pão de hambúrguer' => '1',
            'Carne moída para hambúrguer' => '0.080',
            'Queijo muçarela (fatia)' => '2',
            'Alface americana' => '0.015',
            'Tomate' => '0.030',
            'Molho especial da casa' => '0.020',
        ]],
        'Batata frita média' => ['Porções', 'Porção de 200 g de batata frita sequinha.', '18.00', 'BT-M', 1, [
            'Batata palito congelada' => '0.200',
            'Óleo de soja' => '0.020',
            'Sal refinado' => '0.002',
            'Embalagem de batata média' => '1',
        ]],
        'Batata frita grande' => ['Porções', 'Porção de 350 g de batata frita sequinha.', '26.00', 'BT-G', 2, [
            'Batata palito congelada' => '0.350',
            'Óleo de soja' => '0.035',
            'Sal refinado' => '0.003',
            'Embalagem de batata grande' => '1',
        ]],
        'Espetinho de carne' => ['Espetinhos', 'Espeto de carne bovina (100 g) assado na brasa.', '9.00', 'ESP-C', 1, [
            'Carne bovina em cubos (espeto)' => '0.100',
            'Espeto de bambu' => '1',
            'Sal grosso' => '0.003',
        ]],
        'Espetinho de frango' => ['Espetinhos', 'Espetinho de frango (comprado pronto, só assar).', '8.00', 'ESP-F', 2, [
            'Espetinho de frango pronto' => '1',
        ]],
        'Cerveja lata 350 ml' => ['Bebidas', 'Cerveja gelada, lata de 350 ml.', '7.00', 'CRV-350', 1, [
            'Cerveja lata 350 ml' => '1',
        ]],
        'Refrigerante lata 350 ml' => ['Bebidas', 'Refrigerante gelado, lata de 350 ml.', '6.00', 'REF-350', 2, [
            'Refrigerante lata 350 ml' => '1',
        ]],
    ];

    $rest = $db->prepare('SELECT id FROM restaurantes WHERE slug = ? FOR UPDATE');
    $rest->execute(['restaurante-modelo-cardapim']);
    $idRestaurante = $rest->fetchColumn();
    if ($idRestaurante === false) {
        throw new RuntimeException('Restaurante Modelo nao encontrado.');
    }
    $idRestaurante = (int) $idRestaurante;
    $criados = ['categorias' => 0, 'insumos' => 0, 'produtos' => 0, 'ficha' => 0];

    $buscar = static function (string $tabela, string $nome) use ($db, $idRestaurante): ?int {
        // Tabelas fixas deste arquivo.
        $q = $db->prepare("SELECT id FROM $tabela WHERE id_restaurante = ? AND nome = ?");
        $q->execute([$idRestaurante, $nome]);
        $id = $q->fetchColumn();
        return $id === false ? null : (int) $id;
    };

    $idsCategoria = [];
    $novaCategoria = $db->prepare('INSERT INTO categorias (id_restaurante, nome, descricao, ordem) VALUES (?, ?, ?, ?)');
    foreach ($categorias as $nome => [$descricao, $ordem]) {
        $id = $buscar('categorias', $nome);
        if ($id === null) {
            $novaCategoria->execute([$idRestaurante, $nome, $descricao, $ordem]);
            $id = (int) $db->lastInsertId();
            $criados['categorias']++;
        }
        $idsCategoria[$nome] = $id;
    }

    $idsInsumo = [];
    $novoInsumo = $db->prepare(
        'INSERT INTO insumos (id_restaurante, nome, unidade, estoque_atual, estoque_minimo, custo_unitario) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($insumos as $nome => [$unidade, $estoque, $minimo, $custo]) {
        $id = $buscar('insumos', $nome);
        if ($id === null) {
            $novoInsumo->execute([$idRestaurante, $nome, $unidade, $estoque, $minimo, $custo]);
            $id = (int) $db->lastInsertId();
            $criados['insumos']++;
        }
        $idsInsumo[$nome] = $id;
    }

    $novoProduto = $db->prepare(
        'INSERT INTO produtos (id_restaurante, id_categoria, nome, descricao, preco, codigo, controla_estoque, ordem)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $temFicha = $db->prepare('SELECT COUNT(*) FROM produto_insumos WHERE id_produto = ?');
    $novaLinha = $db->prepare('INSERT INTO produto_insumos (id_restaurante, id_produto, id_insumo, quantidade) VALUES (?, ?, ?, ?)');
    foreach ($produtos as $nome => [$categoria, $descricao, $preco, $codigo, $ordem, $ficha]) {
        $id = $buscar('produtos', $nome);
        if ($id === null) {
            $novoProduto->execute([$idRestaurante, $idsCategoria[$categoria], $nome, $descricao, $preco, $codigo, $ordem]);
            $id = (int) $db->lastInsertId();
            $criados['produtos']++;
        }
        // Ficha tecnica so e criada se o produto ainda nao tiver nenhuma (nao mexe em ficha editada).
        $temFicha->execute([$id]);
        if ((int) $temFicha->fetchColumn() === 0) {
            foreach ($ficha as $insumo => $quantidade) {
                $novaLinha->execute([$idRestaurante, $id, $idsInsumo[$insumo], $quantidade]);
                $criados['ficha']++;
            }
        }
    }
    return $criados;
};
