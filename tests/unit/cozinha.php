<?php

declare(strict_types=1);

// Leitura da cozinha em SQLite na memoria: isolamento, ordem, status e itens cancelados.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Repositories\Gerencia\CozinhaRepository;
use Cardapim\Services\Cozinha\PainelCozinha;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE mesas (id INTEGER, id_restaurante INTEGER, numero INTEGER)');
$db->exec('CREATE TABLE pedidos (id INTEGER, id_restaurante INTEGER, numero INTEGER, origem TEXT, tipo TEXT, status TEXT, criado_em TEXT, cliente_nome TEXT, comanda_nome TEXT, observacao TEXT, ifood_display_id TEXT, id_mesa INTEGER)');
$db->exec('CREATE TABLE pedido_itens (id INTEGER, id_pedido INTEGER, id_restaurante INTEGER, nome TEXT, quantidade TEXT, unidade TEXT, observacao TEXT, cancelado_em TEXT, criado_em TEXT)');
$db->exec("INSERT INTO mesas VALUES (1, 10, 4), (2, 20, 9)");
$db->exec("INSERT INTO pedidos VALUES
    (1, 10, 21, 'cardapio', 'entrega', 'recebido', '2026-11-15 12:00:00', 'Maria', NULL, 'Sem cebola', NULL, NULL),
    (2, 10, 22, 'mesa', 'mesa', 'aberto', '2026-11-15 12:01:00', NULL, 'Ana', NULL, NULL, 1),
    (3, 10, 23, 'ifood', 'retirada', 'em_preparo', '2026-11-15 12:02:00', 'Jose', NULL, NULL, 'X23', NULL),
    (4, 10, 24, 'cardapio', 'entrega', 'pronto', '2026-11-15 12:03:00', 'Pronto', NULL, NULL, NULL, NULL),
    (5, 20, 31, 'cardapio', 'entrega', 'em_preparo', '2026-11-15 11:59:00', 'Outro', NULL, NULL, NULL, NULL),
    (6, 10, 25, 'cardapio', 'entrega', 'aguardando_pagamento', '2026-11-15 12:04:00', 'Pix', NULL, NULL, NULL, NULL),
    (7, 10, 26, 'cardapio', 'retirada', 'recebido', '2026-11-15 12:05:00', 'Cancelado', NULL, NULL, NULL, NULL)");
$db->exec("INSERT INTO pedido_itens VALUES
    (1, 1, 10, 'Hamburguer', '2.000', 'un', 'Sem salada', NULL, '2026-11-15 12:00:00'),
    (2, 1, 10, 'Batata', '1.000', 'un', NULL, '2026-11-15 12:00:00', '2026-11-15 12:00:00'),
    (3, 2, 10, 'Carne', '0.846', 'kg', 'Bem passada', NULL, '2026-11-15 12:01:00'),
    (4, 3, 10, 'Pizza', '1.000', 'un', NULL, NULL, '2026-11-15 12:02:00'),
    (5, 4, 10, 'Pronto', '1.000', 'un', NULL, NULL, '2026-11-15 12:03:00'),
    (6, 5, 20, 'Fora do tenant', '1.000', 'un', NULL, NULL, '2026-11-15 11:59:00'),
    (7, 6, 10, 'Pix pendente', '1.000', 'un', NULL, NULL, '2026-11-15 12:04:00'),
    (8, 7, 10, 'Excluido', '1.000', 'un', NULL, '2026-11-15 12:05:30', '2026-11-15 12:05:00')");

$erros = [];
$verificar = static function (bool $condicao, string $descricao) use (&$erros): void {
    if (!$condicao) $erros[] = $descricao;
};
$servico = new PainelCozinha($db, 10);
$dados = $servico->painel();
$pedidos = $dados['pedidos'];
$verificar($dados['total'] === 1, 'somente status em_preparo entra na cozinha');
$verificar(array_column($pedidos, 'numero') === [23], 'recebido, aberto, pronto e pedidos de outro restaurante nao aparecem');
$verificar(($pedidos[0]['ifood_display_id'] ?? null) === 'X23', 'identificador do iFood preservado');

// "Aceitar e preparar" muda o pedido de recebido para em_preparo.
// A mesma fila renderizada pelo HTML e pela rota de atualizacao deve passar a incluir o pedido.
$db->exec("UPDATE pedidos SET status = 'em_preparo' WHERE id = 1 AND id_restaurante = 10");
$pedidos = $servico->painel()['pedidos'];
$verificar(array_column($pedidos, 'numero') === [21, 23], 'pedido aparece somente depois de aceitar e preparar, por ordem de chegada');
$verificar(count($pedidos[0]['itens'] ?? []) === 1, 'item cancelado nao aparece apos aceitar');
$verificar(($pedidos[0]['itens'][0]['observacao'] ?? '') === 'Sem salada', 'observacao do item preservada');
$verificar(($pedidos[0]['observacao'] ?? '') === 'Sem cebola', 'observacao geral preservada');

// Ao marcar "Pedido pronto" no caixa, o pedido deixa de ocupar a fila do KDS.
$db->exec("UPDATE pedidos SET status = 'pronto' WHERE id = 1 AND id_restaurante = 10");
$verificar(array_column($servico->painel()['pedidos'], 'numero') === [23], 'pedido pronto sai da cozinha');

// Comanda aberta só entra se seu estado também for alterado para em_preparo.
$db->exec("UPDATE pedidos SET status = 'em_preparo' WHERE id = 2 AND id_restaurante = 10");
$pedidos = $servico->painel()['pedidos'];
$verificar(array_column($pedidos, 'numero') === [22, 23], 'comanda entra apenas se passar para preparo');
$verificar(($pedidos[0]['mesa_numero'] ?? null) === 4 && ($pedidos[0]['comanda_nome'] ?? null) === 'Ana', 'mesa e nome da comanda preservados');
$verificar((new CozinhaRepository($db))->pedidos(999) === [], 'restaurante inexistente nao recebe dados');

if ($erros !== []) {
    foreach ($erros as $erro) fwrite(STDERR, "FALHOU: $erro\n");
    exit(1);
}
echo "Cozinha: somente apos aceite, ordem, itens, observacoes e isolamento OK.\n";
