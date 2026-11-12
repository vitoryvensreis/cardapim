<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Modules\Inventory\BaixaEstoque;
use Cardapim\Modules\Inventory\EstoqueInsuficiente;
use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\PedidoRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Grava o pedido do iFood no sistema: aparece no delivery do caixa (com o aviso de pedido novo),
 * baixa o estoque dos produtos ligados e, se o gerente quiser, ja aceita no iFood.
 * O pedido ja foi vendido no iFood: se faltar estoque no sistema, entra mesmo assim com um aviso.
 */
final class ImportarPedidoIfood
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /**
     * @param array<string,mixed> $pedidoIfood resposta do iFood (GET /order/v1.0/orders/{id})
     * @return array{id:int, numero:int, novo:bool}
     */
    public function importar(array $pedidoIfood, bool $aceitarAutomatico): array
    {
        $p = PedidoIfood::converter($pedidoIfood);
        $existente = $this->existente($p['ifood_id']);
        if ($existente !== null) {
            return $existente + ['novo' => false];
        }
        $catalogo = new IfoodCatalogo($this->db, $this->idRestaurante);
        foreach ($p['itens'] as &$item) {
            if ($item['ifood_item_id'] !== null) {
                $catalogo->guardar($item['ifood_item_id'], $item['nome_ifood'] !== '' ? $item['nome_ifood'] : $item['nome'], $item['codigo_externo']);
            }
            $item['id_produto'] = $catalogo->produtoDoItem($item['ifood_item_id'], $item['codigo_externo'], $item['nome_ifood']);
        }
        unset($item);

        for ($tentativa = 1; ; $tentativa++) {
            try {
                return $this->gravar($p, $aceitarAutomatico) + ['novo' => true];
            } catch (\PDOException $erro) {
                // Numero repetido (outro pedido entrou no mesmo instante) ou o mesmo pedido gravado em paralelo.
                if ($erro->getCode() !== '23000' || $tentativa >= 3) {
                    throw $erro;
                }
                $existente = $this->existente($p['ifood_id']);
                if ($existente !== null) {
                    return $existente + ['novo' => false];
                }
            }
        }
    }

    /** @param array<string,mixed> $p @return array{id:int, numero:int} */
    private function gravar(array $p, bool $aceitar): array
    {
        $agora = date('Y-m-d H:i:s');
        $status = $aceitar ? 'em_preparo' : 'recebido';
        $end = $p['endereco'];
        $dados = $p['dados'];
        $this->db->beginTransaction();
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT id FROM restaurantes WHERE id = ? FOR UPDATE')->execute([$this->idRestaurante]);
            }
            $n = $this->db->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM pedidos WHERE id_restaurante = ?');
            $n->execute([$this->idRestaurante]);
            $numero = (int) $n->fetchColumn();
            $this->db->prepare('INSERT INTO pedidos (id_restaurante, numero, origem, tipo, status, forma_pagamento, pagamento_status, troco_para,
                    subtotal, taxa_entrega, desconto, total, observacao, cliente_nome, cliente_whatsapp, entrega_cep, entrega_logradouro, entrega_numero,
                    entrega_complemento, entrega_bairro, entrega_cidade, entrega_estado, entrega_referencia, entrega_latitude, entrega_longitude,
                    area_descricao, chave_idempotencia, ifood_id, ifood_display_id, ifood_entregue_por, ifood_codigo_coleta, ifood_dados, pago_em, criado_em, atualizado_em)
                VALUES (?, ?, \'ifood\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $this->idRestaurante, $numero, $p['tipo'], $status, $p['forma_pagamento'], $p['pagamento_status'],
                    $p['troco_para'] !== null ? PedidoIfood::decimal($p['troco_para']) : null,
                    PedidoIfood::decimal($p['subtotal']), PedidoIfood::decimal($p['taxa_entrega']), PedidoIfood::decimal($p['desconto']), PedidoIfood::decimal($p['total']),
                    $p['observacao'], $p['cliente_nome'],
                    $end['cep'] ?? null, $end['logradouro'] ?? null, $end['numero'] ?? null, $end['complemento'] ?? null, $end['bairro'] ?? null,
                    $end['cidade'] ?? null, $end['estado'] ?? null, $end['referencia'] ?? null,
                    isset($end['latitude']) ? (string) $end['latitude'] : null, isset($end['longitude']) ? (string) $end['longitude'] : null,
                    $p['tipo'] === 'entrega' ? ($p['entregue_por'] === 'ifood' ? 'Entrega pelo iFood' : 'Entrega da loja (pedido do iFood)') : null,
                    md5('ifood:' . $p['ifood_id']), $p['ifood_id'], $p['display_id'], $p['entregue_por'], $p['codigo_coleta'],
                    json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $p['pagamento_status'] === 'pago' ? $agora : null, $agora, $agora,
                ]);
            $q = $this->db->prepare('SELECT id FROM pedidos WHERE id_restaurante = ? AND ifood_id = ?');
            $q->execute([$this->idRestaurante, $p['ifood_id']]);
            $idPedido = (int) $q->fetchColumn();
            if ($idPedido < 1) {
                throw new \RuntimeException('Pedido do iFood nao gravado.');
            }
            $ins = $this->db->prepare('INSERT INTO pedido_itens (id_pedido, id_restaurante, id_produto, nome, preco_unitario, quantidade, observacao, total, criado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $baixar = [];
            foreach ($p['itens'] as $i) {
                $ins->execute([$idPedido, $this->idRestaurante, $i['id_produto'], $i['nome'], PedidoIfood::decimal($i['preco_unitario']), $i['quantidade'],
                    $i['observacao'], PedidoIfood::decimal($i['preco_unitario'] * $i['quantidade']), $agora]);
                if ($i['id_produto'] !== null) {
                    $baixar[$i['id_produto']] = ($baixar[$i['id_produto']] ?? 0) + $i['baixa'];
                }
            }
            $semLigacao = array_values(array_unique(array_map(static fn (array $i): string => $i['nome_ifood'] ?: $i['nome'],
                array_filter($p['itens'], static fn (array $i): bool => $i['id_produto'] === null))));
            [$consumo, $faltou] = $this->baixarEstoque($baixar);
            if ($faltou !== []) {
                $dados['avisos'][] = 'Faltou estoque no sistema para: ' . implode(', ', $faltou) . '. Confira o estoque.';
            }
            if ($semLigacao !== []) {
                $dados['avisos'][] = 'Sem produto ligado (o estoque não baixou): ' . implode(', ', $semLigacao) . '. Ligue em Integrações, iFood, Cardápio do iFood.';
            }
            $this->db->prepare('UPDATE pedidos SET consumo_estoque = ?, ifood_dados = ? WHERE id = ? AND id_restaurante = ?')
                ->execute([$consumo !== [] ? json_encode($consumo, JSON_THROW_ON_ERROR) : null, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $idPedido, $this->idRestaurante]);
            $repo = new PedidoRepository($this->db);
            $repo->registrarEvento($this->idRestaurante, $idPedido, 'recebido', 'Pedido recebido do iFood' . ($p['display_id'] ? ' (código ' . $p['display_id'] . ')' : '') . '.');
            if ($aceitar) {
                $repo->registrarEvento($this->idRestaurante, $idPedido, 'em_preparo', 'Aceito automaticamente. Em preparo.');
                IfoodAcoes::enfileirar($this->db, $this->idRestaurante, $idPedido, 'confirmar');
            }
            TempoReal::registrar($this->db, $this->idRestaurante, 'pedidos', 'pedido.novo', ['id' => $idPedido, 'numero' => $numero, 'origem' => 'ifood']);
            $this->db->commit();
            return ['id' => $idPedido, 'numero' => $numero];
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }

    /**
     * Baixa tudo de uma vez; se faltar algo, baixa produto por produto e anota o que faltou.
     * @param array<int,int> $itens id_produto => quantidade
     * @return array{0:array<int,string>, 1:list<string>} consumo e nomes dos produtos sem estoque
     */
    private function baixarEstoque(array $itens): array
    {
        if ($itens === []) {
            return [[], []];
        }
        $baixa = new BaixaEstoque($this->db);
        try {
            return [$baixa->baixar($this->idRestaurante, $itens), []];
        } catch (EstoqueInsuficiente | InvalidArgumentException) {
            // segue produto por produto
        }
        $milesimos = [];
        $faltou = [];
        foreach ($itens as $idProduto => $quantidade) {
            try {
                foreach ($baixa->baixar($this->idRestaurante, [$idProduto => $quantidade]) as $idInsumo => $qtd) {
                    $milesimos[$idInsumo] = ($milesimos[$idInsumo] ?? 0) + Quantidade::paraMilesimos($qtd);
                }
            } catch (EstoqueInsuficiente | InvalidArgumentException) {
                $faltou[] = $this->nomeProduto($idProduto);
            }
        }
        ksort($milesimos);
        return [array_map(static fn (int $m): string => Quantidade::paraDecimal($m), $milesimos), $faltou];
    }

    private function nomeProduto(int $id): string
    {
        $q = $this->db->prepare('SELECT nome FROM produtos WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $this->idRestaurante]);
        return (string) ($q->fetchColumn() ?: 'produto');
    }

    /** @return array{id:int, numero:int}|null */
    private function existente(string $ifoodId): ?array
    {
        $q = $this->db->prepare('SELECT id, numero FROM pedidos WHERE id_restaurante = ? AND ifood_id = ?');
        $q->execute([$this->idRestaurante, $ifoodId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['id' => (int) $r['id'], 'numero' => (int) $r['numero']];
    }
}
