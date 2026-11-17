<?php

declare(strict_types=1);

// Integracao (MySQL): complementos, ligacao com produtos, disponibilidade, preco da sacola e baixa de estoque.
// Tudo dentro de uma transacao desfeita no final: nada e persistido.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Catalog\Complementos;
use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Repositories\Estoque\ComplementoRepository;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\ComplementoService;
use Cardapim\Services\Pedidos\LinhasSacola;
use Cardapim\Shared\Esquema;

$db = Database::connect();
$fail = static function (string $msg): never {
    throw new RuntimeException($msg);
};
$exigir = static function (bool $cond, string $msg) use ($fail): void {
    if (!$cond) {
        $fail($msg);
    }
};
$recusa = static function (callable $acao, string $descricao) use ($fail): void {
    try {
        $acao();
    } catch (PDOException) {
        return;
    }
    $fail("Banco aceitou: $descricao");
};
$id = static fn (): int => (int) $db->lastInsertId();

Esquema::esquecer();
$exigir(Esquema::tem($db, 'complementos'), 'Migracao dos complementos nao aplicada.');

$db->beginTransaction();
try {
    $planId = (int) $db->query('SELECT id FROM planos ORDER BY id LIMIT 1')->fetchColumn();
    $rest = $db->prepare('INSERT INTO restaurantes (nome, slug, plano_id, status_rest) VALUES (?, ?, ?, ?)');
    $rest->execute(['CI Complementos A', 'ci-complementos-a', $planId, 'ativo']);
    $a = $id();
    $rest->execute(['CI Complementos B', 'ci-complementos-b', $planId, 'ativo']);
    $b = $id();

    $ins = $db->prepare('INSERT INTO insumos (id_restaurante, nome, unidade, estoque_atual, estoque_minimo, ativo) VALUES (?, ?, ?, ?, ?, 1)');
    $ins->execute([$a, 'Massa', 'un', '10', '0']);
    $massa = $id();
    $ins->execute([$a, 'Catupiry', 'kg', '0.100', '0']);
    $catupiry = $id();
    $ins->execute([$b, 'Bacon B', 'kg', '1', '0']);
    $baconB = $id();

    $prod = $db->prepare('INSERT INTO produtos (id_restaurante, nome, preco, controla_estoque, disponivel) VALUES (?, ?, ?, 1, 1)');
    $prod->execute([$a, 'Pizza CI', '40.00']);
    $pizza = $id();
    $db->prepare('INSERT INTO produto_insumos (id_restaurante, id_produto, id_insumo, quantidade) VALUES (?, ?, ?, ?)')->execute([$a, $pizza, $massa, '1']);
    $prod->execute([$b, 'Lanche B', '20.00']);
    $lancheB = $id();

    $repo = new ComplementoRepository($db);
    $borda = $repo->criarGrupo($a, 'Borda', 1, 1, 1);
    $catOp = $repo->criarOpcao($a, $borda, ['nome' => 'Catupiry', 'descricao' => null, 'preco' => '8.00', 'codigo' => 'BORDA-CAT', 'imagem_path' => null, 'disponivel' => 1, 'ordem' => 0]);
    $repo->salvarFicha($a, $catOp, [$catupiry => '0.050']);
    $semOp = $repo->criarOpcao($a, $borda, ['nome' => 'Sem borda', 'descricao' => null, 'preco' => '0.00', 'codigo' => null, 'imagem_path' => null, 'disponivel' => 1, 'ordem' => 1]);
    $grupoB = $repo->criarGrupo($b, 'Extras B', 0, 2, 1);
    $opB = $repo->criarOpcao($b, $grupoB, ['nome' => 'Bacon B', 'descricao' => null, 'preco' => '5.00', 'codigo' => null, 'imagem_path' => null, 'disponivel' => 1, 'ordem' => 0]);
    $repo->salvarGruposDoProduto($a, $pizza, [$borda]);

    // Isolamento: o banco recusa misturar restaurantes.
    $recusa(fn () => $repo->salvarGruposDoProduto($a, $pizza, [$grupoB]), 'grupo de outro restaurante no produto');
    $recusa(fn () => $repo->criarOpcao($a, $grupoB, ['nome' => 'X', 'descricao' => null, 'preco' => '1.00', 'codigo' => null, 'imagem_path' => null, 'disponivel' => 1, 'ordem' => 0]), 'opcao em grupo de outro restaurante');
    $recusa(fn () => $repo->salvarFicha($a, $catOp, [$baconB => '0.010']), 'ficha com insumo de outro restaurante');
    $recusa(fn () => $repo->criarGrupo($a, 'Ruim', 3, 1, 9), 'minimo maior que o maximo');
    $recusa(fn () => $repo->criarOpcao($a, $borda, ['nome' => 'Duplicado', 'descricao' => null, 'preco' => '1.00', 'codigo' => 'BORDA-CAT', 'imagem_path' => null, 'disponivel' => 1, 'ordem' => 3]), 'codigo repetido');
    $repo->salvarGruposDoProduto($a, $pizza, [$borda]); // a tentativa recusada acima apagou a ligacao antes de falhar
    $exigir(count($repo->grupos($a)) === 1 && $repo->grupos($a)[0]['produtos'] === 1, 'Grupo listado com 1 produto.');
    $exigir($repo->idsGruposDoProduto($a, $pizza) === [$borda], 'Grupos do produto.');
    $exigir($repo->queUsamInsumo($a, $catupiry) === ['Catupiry'], 'Complementos que usam o insumo.');

    // Disponibilidade: com opcao vendavel o produto vende; sem nenhuma opcao da borda obrigatoria, nao.
    $disp = (new DisponibilidadeProdutos($db))->verificar($a, [$pizza]);
    $exigir($disp[$pizza]['disponivel'] === true, 'Pizza deveria estar disponivel.');
    $repo->definirDisponivel($a, $semOp, false);
    $db->prepare('UPDATE insumos SET estoque_atual = 0.010 WHERE id = ?')->execute([$catupiry]); // nao da para 1 borda (0,050)
    $disp = (new DisponibilidadeProdutos($db))->verificar($a, [$pizza]);
    $exigir($disp[$pizza]['disponivel'] === false && $disp[$pizza]['motivo'] === DisponibilidadeProdutos::MOTIVO_SEM_COMPLEMENTO, 'Borda obrigatoria sem opcao deveria bloquear.');
    $repo->definirDisponivel($a, $semOp, true);
    $db->prepare('UPDATE insumos SET estoque_atual = 0.100 WHERE id = ?')->execute([$catupiry]);

    // Sacola: preco do servidor com os complementos; escolhas invalidas recusadas.
    $linhas = LinhasSacola::formato([['id' => $pizza, 'quantidade' => 2, 'complementos' => [['id' => $catOp, 'quantidade' => 1]]]], 10);
    $c = LinhasSacola::completar($db, $a, $linhas);
    $exigir(!$c['invalida'] && $c['linhas'][0]['centavos'] === 4800 && $c['linhas'][0]['total'] === 9600, 'Preco com complemento: 2 x (40 + 8).');
    $exigir($c['linhas'][0]['complementos_texto'] === 'Borda: Catupiry', 'Texto do complemento.');
    $exigir($c['linhas'][0]['insumos_complementos'] === [$catupiry => 100], 'Consumo do complemento x 2.');
    $sem = LinhasSacola::completar($db, $a, LinhasSacola::formato([['id' => $pizza, 'quantidade' => 1]], 10));
    $exigir($sem['invalida'] && str_contains((string) $sem['mensagem'], 'Borda'), 'Borda obrigatoria sem escolha recusada.');
    $fora = LinhasSacola::completar($db, $a, LinhasSacola::formato([['id' => $pizza, 'quantidade' => 1, 'complementos' => [['id' => $opB, 'quantidade' => 1]]]], 10));
    $exigir($fora['invalida'], 'Complemento de outro restaurante recusado.');

    // Baixa: produto + complementos na mesma transacao.
    $consumo = (new BaixaEstoque($db))->baixarMilesimos($a, [$pizza => 2000], $c['linhas'][0]['insumos_complementos']);
    $exigir($consumo === [$massa => '2.000', $catupiry => '0.100'], 'Consumo com complementos: ' . json_encode($consumo));
    $saldo = (string) $db->query('SELECT estoque_atual FROM insumos WHERE id = ' . $catupiry)->fetchColumn();
    $exigir($saldo === '0.000', 'Catupiry baixado.');

    // Item do pedido guarda a copia dos complementos (CHECK do total).
    $recusa(fn () => $db->prepare('INSERT INTO pedido_item_complementos (id_restaurante, id_pedido_item, grupo, nome, preco_unitario, quantidade, total) VALUES (?, 0, ?, ?, ?, ?, ?)')
        ->execute([$a, 'Borda', 'Catupiry', '8.00', 2, '15.00']), 'total do complemento errado');

    // Servico: validacao antes de gravar (sem abrir transacao).
    $autor = new UsuarioLogado(1, 'gerente', 'CI', 'Complementos', true, $a, 'ci-complementos-a', 'CI Complementos A', 'ativo');
    $svc = new ComplementoService($db, $autor, $a);
    $r = $svc->salvar(null, ['nome' => 'Molhos', 'minimo' => '2', 'maximo' => '1', 'opcoes' => [['nome' => 'Alho', 'preco' => '1,00', 'disponivel' => '1']]]);
    $exigir(!$r->ok && isset($r->erros['maximo']), 'Minimo maior que maximo recusado.');
    $r = $svc->salvar(null, ['nome' => 'Molhos', 'minimo' => '0', 'maximo' => '2', 'opcoes' => []]);
    $exigir(!$r->ok && isset($r->erros['opcoes']), 'Grupo sem opcoes recusado.');
    $r = $svc->salvar(null, ['nome' => 'Molhos', 'minimo' => '0', 'maximo' => '2', 'opcoes' => [['nome' => 'Alho', 'preco' => 'abc', 'disponivel' => '1'], ['nome' => 'alho', 'preco' => '1,00', 'codigo' => 'BORDA-CAT']]]);
    $exigir(!$r->ok && isset($r->erros['opcoes.0.preco'], $r->erros['opcoes.1.nome'], $r->erros['opcoes.1.codigo']), 'Preco invalido, nome e codigo repetidos recusados.');

    // Arquivar o grupo tira dos produtos.
    $repo->arquivarGrupo($a, $borda);
    $exigir($repo->idsGruposDoProduto($a, $pizza) === [] && Complementos::carregar($db, $a, [$pizza]) === [], 'Grupo arquivado sai do produto.');

    $db->rollBack();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

echo "Complementos: cadastro, isolamento, disponibilidade, preco da sacola e baixa testados sem persistir dados.\n";
