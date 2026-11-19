<?php

declare(strict_types=1);

// Integracao (MySQL): "Contar as unidades deste produto" (revenda: Coca-Cola 10 un).
// Cria o insumo proprio escondido, baixa e devolve pela regra de sempre, zera e sai de venda,
// preserva vendas feitas com a tela aberta, acompanha o nome, arquiva ao trocar de opcao e
// recusa edicao/exclusao direta pela aba Insumos. Restaurante proprio, apagado no final.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\InsumoService;
use Cardapim\Services\Gerencia\ProdutoService;

$db = Database::connect();
$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if ($condicao) {
        echo "  ok  $descricao\n";
        return;
    }
    echo "  FALHOU $descricao\n";
    $falhas++;
};
$estoque = static function (int $idInsumo) use ($db): string {
    $q = $db->prepare('SELECT estoque_atual FROM insumos WHERE id = ?');
    $q->execute([$idInsumo]);
    return (string) $q->fetchColumn();
};

$slug = 'ci-estoque-produto';
$limpar = static function () use ($db, $slug): void {
    $q = $db->prepare('SELECT id FROM restaurantes WHERE slug = ?');
    $q->execute([$slug]);
    $id = $q->fetchColumn();
    if ($id === false) {
        return;
    }
    foreach (['produto_insumos', 'produto_porcoes', 'produtos', 'insumos', 'categorias'] as $tabela) {
        $db->prepare("DELETE FROM $tabela WHERE id_restaurante = ?")->execute([(int) $id]);
    }
    $db->prepare('DELETE FROM restaurantes WHERE id = ?')->execute([(int) $id]);
};

$limpar();
try {
    $planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO restaurantes (nome, slug, plano_id, status_rest) VALUES (?, ?, ?, ?)')
        ->execute(['CI Estoque Produto', $slug, $planId, 'ativo']);
    $q = $db->prepare('SELECT id FROM restaurantes WHERE slug = ?');
    $q->execute([$slug]);
    $rest = (int) $q->fetchColumn();
    $autor = new UsuarioLogado(0, 'gerente', 'CI', 'Estoque', true, $rest, $slug, 'CI Estoque Produto', 'ativo');
    $servico = new ProdutoService($db, $autor, $rest);
    $insumos = new InsumoRepository($db);

    // Um insumo comum com o mesmo nome (jeito antigo) nao pode atrapalhar.
    $insumos->criar($rest, ['nome' => 'Coca-Cola', 'unidade' => 'un', 'estoque_atual' => '5.000', 'estoque_minimo' => '0.000', 'custo_unitario' => null, 'ativo' => 1]);

    $base = ['nome' => 'Coca-Cola', 'preco' => '8,00', 'unidade_venda' => 'un', 'disponivel' => '1', 'controle' => 'proprio'];
    $res = $servico->salvar(null, $base + ['estoque_quantidade' => '']);
    $ok(!$res->ok && isset($res->erros['estoque_quantidade']), 'quantidade obrigatoria ao criar');
    $res = $servico->salvar(null, $base + ['estoque_quantidade' => '2,5']);
    $ok(!$res->ok && isset($res->erros['estoque_quantidade']), 'por unidade nao aceita fracao');

    $res = $servico->salvar(null, $base + ['estoque_quantidade' => '10', 'estoque_minimo_produto' => '3', 'estoque_custo' => '3,50']);
    $ok($res->ok && $res->id !== null, 'produto criado contando as proprias unidades');
    $idProduto = (int) $res->id;
    $proprio = $insumos->doProduto($rest, $idProduto);
    $ok($proprio !== null && $proprio['estoque_atual'] === '10.000' && $proprio['estoque_minimo'] === '3.000', 'estoque proprio com 10 e aviso em 3');
    $ok(($proprio['nome'] ?? '') === 'Coca-Cola (produto)', 'nome nao bate com o insumo comum de mesmo nome');
    $idInsumo = (int) ($proprio['id'] ?? 0);

    $nomesLista = array_column($insumos->listar($rest), 'nome');
    $ok(!in_array('Coca-Cola (produto)', $nomesLista, true) && in_array('Coca-Cola', $nomesLista, true), 'aba Insumos nao mostra o estoque proprio');
    $ok(!isset($insumos->mapaParaFicha($rest)[$idInsumo]), 'estoque proprio nao aparece para outras fichas');
    $resumo = array_column($insumos->listar($rest, true), 'id_produto_proprio', 'id');
    $ok((int) ($resumo[$idInsumo] ?? 0) === $idProduto, 'resumo do estoque inclui e aponta para o produto');

    $disp = new DisponibilidadeProdutos($db);
    $d = $disp->verificar($rest, [$idProduto])[$idProduto] ?? [];
    $ok(($d['disponivel'] ?? false) === true && (int) ($d['porcoes'] ?? 0) === 10, 'da para vender 10');

    $baixa = new BaixaEstoque($db);
    $consumo = $baixa->baixar($rest, [$idProduto => 3]);
    $ok($estoque($idInsumo) === '7.000', 'venda de 3 deixa 7');
    $baixa->devolver($rest, $consumo);
    $ok($estoque($idInsumo) === '10.000', 'cancelamento devolve');

    // Edicao com a tela aberta: 2 vendidas enquanto isso; salvar sem mexer no numero preserva 8.
    $baixa->baixar($rest, [$idProduto => 2]);
    $res = $servico->salvar($idProduto, $base + ['nome' => 'Coca-Cola Lata', 'estoque_quantidade' => '10', 'estoque_quantidade_antes' => '10', 'estoque_minimo_produto' => '3']);
    $ok($res->ok && $estoque($idInsumo) === '8.000', 'editar sem mudar o numero nao apaga vendas');
    $ok(($insumos->doProduto($rest, $idProduto)['nome'] ?? '') === 'Coca-Cola Lata', 'estoque proprio acompanha o nome do produto');
    $res = $servico->salvar($idProduto, $base + ['nome' => 'Coca-Cola Lata', 'estoque_quantidade' => '24', 'estoque_quantidade_antes' => '10']);
    $ok($res->ok && $estoque($idInsumo) === '24.000', 'mudar o numero corrige o estoque');

    $baixa->baixar($rest, [$idProduto => 24]);
    $d = $disp->verificar($rest, [$idProduto])[$idProduto] ?? [];
    $ok(($d['disponivel'] ?? true) === false, 'zerou: sai de venda sozinho');
    try {
        $baixa->baixar($rest, [$idProduto => 1]);
        $ok(false, 'venda sem estoque recusada');
    } catch (EstoqueInsuficiente) {
        $ok(true, 'venda sem estoque recusada');
    }

    $insumoServico = new InsumoService($db, $autor, $rest);
    $ok(!$insumoServico->excluir($idInsumo)->ok, 'aba Insumos nao exclui o estoque do produto');
    $ok(!$insumoServico->salvar($idInsumo, ['nome' => 'X', 'unidade' => 'un', 'estoque_atual' => '1', 'estoque_minimo' => '0', 'ativo' => '1'])->ok, 'aba Insumos nao edita o estoque do produto');
    $ok($insumoServico->repor($idInsumo, '6')->ok && $estoque($idInsumo) === '6.000', 'Somar (chegou mais) funciona');

    // Trocar para "Nao controlar": estoque proprio arquivado; voltar cria outro.
    $res = $servico->salvar($idProduto, ['controle' => 'livre'] + $base);
    $ok($res->ok && $insumos->doProduto($rest, $idProduto) === null, 'trocar de opcao arquiva o estoque proprio');
    $res = $servico->salvar($idProduto, $base + ['estoque_quantidade' => '12']);
    $novo = $insumos->doProduto($rest, $idProduto);
    $ok($res->ok && $novo !== null && $novo['id'] !== $idInsumo && $novo['estoque_atual'] === '12.000', 'voltar a contar cria um novo estoque');

    // Por quilo: conta em kg e baixa proporcional.
    $res = $servico->salvar(null, ['nome' => 'Castanha', 'preco' => '80,00', 'unidade_venda' => 'kg', 'disponivel' => '1', 'controle' => 'proprio', 'estoque_quantidade' => '2,500']);
    $castanha = (int) $res->id;
    $ic = $insumos->doProduto($rest, $castanha);
    $baixa->baixarMilesimos($rest, [$castanha => 350]);
    $ok($res->ok && ($ic['unidade'] ?? '') === 'kg' && $estoque((int) $ic['id']) === '2.150', 'por quilo: 0,350 kg vendido deixa 2,150 kg');

    $ok($servico->excluir($idProduto)->ok && $insumos->doProduto($rest, $idProduto) === null, 'excluir o produto arquiva o estoque proprio');
} catch (Throwable $e) {
    echo '  FALHOU erro inesperado: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $falhas++;
} finally {
    $limpar();
}

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s) no estoque proprio do produto.\n");
    exit(1);
}
echo "OK: estoque contado no proprio produto.\n";
