<?php

declare(strict_types=1);

// Integracao (MySQL): categorias, insumos, produtos, ficha tecnica, disponibilidade e baixa.
// Tudo dentro de uma transacao desfeita no final: nada e persistido.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;

$db = Database::connect();
$fail = static function (string $msg): never {
    throw new RuntimeException($msg);
};
$recusa = static function (callable $acao, string $descricao) use ($fail): void {
    try {
        $acao();
    } catch (PDOException) {
        return; // o banco recusou, como esperado
    }
    $fail("Banco aceitou: $descricao");
};
$id = static fn (): int => (int) $db->lastInsertId();

$db->beginTransaction();
try {
    $planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
    $rest = $db->prepare('INSERT INTO restaurantes (nome, slug, plano_id, status_rest) VALUES (?, ?, ?, ?)');
    $rest->execute(['CI Estoque A', 'ci-estoque-a', $planId, 'ativo']);
    $a = $id();
    $rest->execute(['CI Estoque B', 'ci-estoque-b', $planId, 'ativo']);
    $b = $id();

    // Categorias
    $cat = $db->prepare('INSERT INTO categorias (id_restaurante, nome, ordem) VALUES (?, ?, ?)');
    $cat->execute([$a, 'Lanches', 1]);
    $catA = $id();
    $cat->execute([$b, 'Lanches', 1]); // mesmo nome em outro restaurante: permitido
    $catB = $id();
    $recusa(fn () => $cat->execute([$a, 'Lanches', 2]), 'categoria duplicada no mesmo restaurante');

    // Insumos
    $ins = $db->prepare('INSERT INTO insumos (id_restaurante, nome, unidade, estoque_atual, estoque_minimo, custo_unitario, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$a, 'Carne moída', 'kg', '1.000', '0.500', '38.9000', 1]);
    $carne = $id();
    $ins->execute([$a, 'Pão brioche', 'un', '10', '5', '1.2000', 1]);
    $pao = $id();
    $ins->execute([$a, 'Queijo fatia', 'un', '2', '10', null, 1]);
    $queijo = $id();
    $ins->execute([$a, 'Leite', 'l', '0.500', '1', '5.4900', 1]);
    $leite = $id();
    $ins->execute([$a, 'Molho antigo', 'kg', '5', '0', null, 0]);
    $inativo = $id();
    $ins->execute([$b, 'Carne B', 'kg', '5', '0', null, 1]);
    $carneB = $id();
    $recusa(fn () => $ins->execute([$a, 'Negativo', 'kg', '-0.001', '0', null, 1]), 'estoque negativo');
    $recusa(fn () => $ins->execute([$a, 'Caixa', 'cx', '1', '0', null, 1]), 'unidade fora de un/kg/l');
    $recusa(fn () => $ins->execute([$a, 'Carne moída', 'kg', '1', '0', null, 1]), 'insumo duplicado');
    $recusa(fn () => $ins->execute([$a, 'Custo negativo', 'kg', '1', '0', '-1', 1]), 'custo negativo');

    // Produtos
    $prod = $db->prepare('INSERT INTO produtos (id_restaurante, id_categoria, nome, preco, controla_estoque, codigo) VALUES (?, ?, ?, ?, ?, ?)');
    $prod->execute([$a, $catA, 'X-Burguer', '25.90', 1, 'XB01']);
    $burger = $id();
    $prod->execute([$a, $catA, 'Milkshake', '14.00', 1, null]);
    $milk = $id();
    $prod->execute([$a, null, 'Refrigerante lata', '6.00', 0, null]);
    $refri = $id();
    $prod->execute([$a, $catA, 'Lanche antigo', '10.00', 1, null]);
    $antigo = $id();
    $prod->execute([$a, $catA, 'Sem ficha', '9.00', 1, null]);
    $semFicha = $id();
    $recusa(fn () => $prod->execute([$a, $catB, 'Categoria de outro', '1', 1, null]), 'produto com categoria de outro restaurante');
    $recusa(fn () => $prod->execute([$a, $catA, 'Preco negativo', '-1', 1, null]), 'preco negativo');
    $recusa(fn () => $prod->execute([$a, $catA, 'X-Burguer', '1', 1, null]), 'produto duplicado');
    $recusa(fn () => $prod->execute([$a, $catA, 'Outro', '1', 1, 'XB01']), 'codigo duplicado');

    // Ficha tecnica
    $ficha = $db->prepare('INSERT INTO produto_insumos (id_restaurante, id_produto, id_insumo, quantidade) VALUES (?, ?, ?, ?)');
    $ficha->execute([$a, $burger, $carne, '0.080']);
    $ficha->execute([$a, $burger, $pao, '1']);
    $ficha->execute([$a, $burger, $queijo, '1']);
    $ficha->execute([$a, $milk, $leite, '0.300']);
    $ficha->execute([$a, $antigo, $inativo, '0.050']);
    $recusa(fn () => $ficha->execute([$a, $burger, $carneB, '0.1']), 'ficha com insumo de outro restaurante');
    $recusa(fn () => $ficha->execute([$b, $burger, $carneB, '0.1']), 'ficha com produto de outro restaurante');
    $recusa(fn () => $ficha->execute([$a, $milk, $pao, '0']), 'quantidade zero');
    $recusa(fn () => $ficha->execute([$a, $burger, $pao, '2']), 'insumo repetido na ficha');

    // Disponibilidade
    $disp = new DisponibilidadeProdutos($db);
    $d = $disp->verificar($a);
    if (!$d[$burger]['disponivel'] || $d[$burger]['porcoes'] !== 2) {
        $fail('X-Burguer deveria render 2 (limitado pelo queijo).');
    }
    if (!$d[$milk]['disponivel'] || $d[$milk]['porcoes'] !== 1) {
        $fail('Milkshake deveria render 1 (0,5 L / 0,3 L).');
    }
    if (!$d[$refri]['disponivel'] || $d[$refri]['porcoes'] !== null) {
        $fail('Produto sem controle de estoque deve estar sempre disponivel.');
    }
    if ($d[$antigo]['disponivel'] || $d[$antigo]['motivo'] !== DisponibilidadeProdutos::MOTIVO_INSUMO_INATIVO) {
        $fail('Produto com insumo inativo deve ficar indisponivel.');
    }
    if (!$d[$semFicha]['disponivel']) {
        $fail('Produto sem ficha tecnica nao tem o que faltar.');
    }
    if ($disp->verificar($b, [$burger]) !== []) {
        $fail('Disponibilidade vazou produto de outro restaurante.');
    }

    // Baixa: 2 X-Burguer + 1 Milkshake
    $baixa = new BaixaEstoque($db);
    $consumo = $baixa->baixar($a, [$burger => 2, $milk => 1]);
    $esperado = [$carne => '0.160', $pao => '2.000', $queijo => '2.000', $leite => '0.300'];
    ksort($esperado);
    if ($consumo !== $esperado) {
        $fail('Consumo calculado incorretamente: ' . json_encode($consumo));
    }
    $saldo = static function (int $insumo) use ($db): string {
        $q = $db->prepare('SELECT estoque_atual FROM insumos WHERE id = ?');
        $q->execute([$insumo]);
        return (string) $q->fetchColumn();
    };
    if ($saldo($carne) !== '0.840' || $saldo($pao) !== '8.000' || $saldo($queijo) !== '0.000' || $saldo($leite) !== '0.200') {
        $fail('Saldos apos a baixa incorretos.');
    }

    $d = $disp->verificar($a, [$burger, $milk]);
    if ($d[$burger]['disponivel'] || $d[$burger]['motivo'] !== DisponibilidadeProdutos::MOTIVO_SEM_INSUMO
        || $d[$burger]['faltando'] !== ['Queijo fatia'] || $d[$burger]['porcoes'] !== 0) {
        $fail('Sem queijo, o X-Burguer deve ficar indisponivel.');
    }
    if ($d[$milk]['disponivel']) {
        $fail('Com 0,2 L de leite, o milkshake (0,3 L) deve ficar indisponivel.');
    }

    // Falta de insumo: recusa tudo, nada muda
    try {
        $baixa->baixar($a, [$burger => 1]);
        $fail('Venda sem queijo foi aceita.');
    } catch (EstoqueInsuficiente $e) {
        if (array_column($e->faltas, 'nome') !== ['Queijo fatia'] || $saldo($carne) !== '0.840' || $saldo($pao) !== '8.000') {
            $fail('Venda recusada alterou o estoque ou informou falta errada.');
        }
    }

    // Isolamento e produto pausado
    try {
        $baixa->baixar($b, [$burger => 1]);
        $fail('Baixa com produto de outro restaurante aceita.');
    } catch (InvalidArgumentException) {
    }
    $db->prepare('UPDATE produtos SET disponivel = 0 WHERE id = ?')->execute([$refri]);
    try {
        $baixa->baixar($a, [$refri => 1]);
        $fail('Produto pausado foi vendido.');
    } catch (InvalidArgumentException) {
    }
    if ($disp->verificar($a, [$refri])[$refri]['motivo'] !== DisponibilidadeProdutos::MOTIVO_PAUSADO) {
        $fail('Produto pausado deve informar o motivo.');
    }

    // Cancelamento: devolve exatamente o que saiu
    $baixa->devolver($a, $consumo);
    if ($saldo($carne) !== '1.000' || $saldo($pao) !== '10.000' || $saldo($queijo) !== '2.000' || $saldo($leite) !== '0.500') {
        $fail('Devolucao nao restaurou os saldos.');
    }
    try {
        $baixa->devolver($a, [$carneB => '1.000']);
        $fail('Devolucao em insumo de outro restaurante aceita.');
    } catch (InvalidArgumentException) {
    }
    $recusa(fn () => $db->prepare('UPDATE insumos SET estoque_atual = estoque_atual - 5 WHERE id = ?')->execute([$queijo]), 'saldo negativo por UPDATE direto');

    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

echo "Categorias, insumos, produtos, ficha tecnica, disponibilidade e baixa testados sem persistir dados.\n";
