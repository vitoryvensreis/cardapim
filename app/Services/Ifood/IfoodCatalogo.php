<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use PDO;

/**
 * Cardapio do iFood ligado aos produtos do restaurante.
 * - Ligacao automatica: mesmo codigo (codigo PDV do iFood = codigo do produto) ou mesmo nome.
 * - Venda no iFood baixa o estoque do produto ligado (ImportarPedidoIfood).
 * - Disponibilidade do produto (estoque da ficha tecnica, pausa, arquivado) vai para o iFood: so envia o que mudou.
 */
final class IfoodCatalogo
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /** Busca os itens no iFood, guarda e liga automaticamente os que tiverem o mesmo codigo ou nome. @return array{total:int, novos:int, ligados:int} */
    public function importar(IfoodConta $conta): array
    {
        $loja = $conta->loja() ?? throw new IntegracaoErro('Escolha a loja do iFood primeiro.');
        $token = $conta->token();
        $total = 0;
        $novos = 0;
        foreach ($conta->api()->catalogos($token, $loja) as $catalogo) {
            foreach ($conta->api()->itensCatalogo($token, $loja, $catalogo) as $item) {
                $total++;
                $novos += $this->guardar($item['id'], $item['nome'], $item['codigo'], $item['produto'], $item['categoria'], $item['preco'], $item['status']) ? 1 : 0;
            }
        }
        return ['total' => $total, 'novos' => $novos, 'ligados' => $this->ligarAutomatico()];
    }

    /** Grava ou atualiza um item do iFood. true = item novo. Se o iFood mostra outra situacao, envia de novo na proxima rodada. */
    public function guardar(string $idItem, string $nome, ?string $codigo, ?string $idProdutoIfood = null, ?string $categoria = null, ?float $preco = null, ?string $status = null): bool
    {
        $agora = date('Y-m-d H:i:s');
        $q = $this->db->prepare('SELECT id FROM ifood_itens WHERE id_restaurante = ? AND ifood_item_id = ?');
        $q->execute([$this->idRestaurante, $idItem]);
        $id = $q->fetchColumn();
        if ($id === false) {
            $this->db->prepare('INSERT INTO ifood_itens (id_restaurante, ifood_item_id, ifood_produto_id, nome, codigo_externo, categoria, preco, status_ifood, visto_em, criado_em, atualizado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$this->idRestaurante, $idItem, $idProdutoIfood, $nome, $codigo, $categoria, $preco !== null ? number_format($preco, 2, '.', '') : null, $status, $agora, $agora, $agora]);
            return true;
        }
        $this->db->prepare('UPDATE ifood_itens SET nome = ?, codigo_externo = COALESCE(?, codigo_externo), ifood_produto_id = COALESCE(?, ifood_produto_id),
                categoria = COALESCE(?, categoria), preco = COALESCE(?, preco), status_ifood = COALESCE(?, status_ifood),
                enviado_status = CASE WHEN ? IS NOT NULL AND enviado_status IS NOT NULL AND enviado_status <> ? THEN NULL ELSE enviado_status END,
                visto_em = ?, atualizado_em = ?
            WHERE id = ? AND id_restaurante = ?')
            ->execute([$nome, $codigo, $idProdutoIfood, $categoria, $preco !== null ? number_format($preco, 2, '.', '') : null, $status, $status, (string) $status,
                $agora, $agora, (int) $id, $this->idRestaurante]);
        return false;
    }

    /** Liga os itens ainda soltos ao produto de mesmo codigo ou, se nao houver, de mesmo nome. @return int quantos ligou */
    public function ligarAutomatico(): int
    {
        $q = $this->db->prepare('SELECT id, nome, codigo_externo FROM ifood_itens WHERE id_restaurante = ? AND id_produto IS NULL');
        $q->execute([$this->idRestaurante]);
        $ligados = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $produto = $this->produtoParecido($item['codigo_externo'] !== null ? (string) $item['codigo_externo'] : null, (string) $item['nome']);
            if ($produto !== null) {
                $this->db->prepare("UPDATE ifood_itens SET id_produto = ?, vinculo = 'automatico', enviado_status = NULL, enviado_estoque = NULL WHERE id = ? AND id_restaurante = ? AND id_produto IS NULL")
                    ->execute([$produto, (int) $item['id'], $this->idRestaurante]);
                $ligados++;
            }
        }
        return $ligados;
    }

    /**
     * Produto do restaurante para um item vindo em pedido: ligacao do proprio item, outro item do iFood com o mesmo
     * codigo PDV ou o mesmo nome ja ligado (so se todos apontam para o mesmo produto), ou produto daqui com o mesmo
     * codigo/nome. Achado por codigo ou nome, o item novo fica ligado sozinho (o iFood pode recriar o item com outro id).
     */
    public function produtoDoItem(?string $idItem, ?string $codigo, string $nome): ?int
    {
        if ($idItem !== null) {
            $q = $this->db->prepare('SELECT id_produto FROM ifood_itens WHERE id_restaurante = ? AND ifood_item_id = ? AND id_produto IS NOT NULL');
            $q->execute([$this->idRestaurante, $idItem]);
            $id = $q->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        $produto = null;
        if ($codigo !== null) {
            $produto = $this->unicoLigado('codigo_externo = ?', [$codigo], $idItem);
        }
        if ($produto === null && trim($nome) !== '') {
            $produto = $this->unicoLigado('LOWER(nome) = ?', [mb_strtolower(trim($nome))], $idItem);
        }
        $produto ??= $this->produtoParecido($codigo, $nome);
        if ($produto !== null && $idItem !== null) {
            $this->db->prepare("UPDATE ifood_itens SET id_produto = ?, vinculo = 'automatico' WHERE id_restaurante = ? AND ifood_item_id = ? AND id_produto IS NULL")
                ->execute([$produto, $this->idRestaurante, $idItem]);
        }
        return $produto;
    }

    /** Produto ligado a outros itens do iFood que batem com a condicao, se todos apontam para o MESMO produto. @param list<string> $valores */
    private function unicoLigado(string $condicao, array $valores, ?string $menosItem): ?int
    {
        $sql = 'SELECT DISTINCT id_produto FROM ifood_itens WHERE id_restaurante = ? AND id_produto IS NOT NULL AND ' . $condicao;
        $params = [$this->idRestaurante, ...$valores];
        if ($menosItem !== null) {
            $sql .= ' AND ifood_item_id <> ?';
            $params[] = $menosItem;
        }
        $q = $this->db->prepare($sql);
        $q->execute($params);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /** Liga (ou solta, com null) um item a um produto deste restaurante. */
    public function ligar(int $idItem, ?int $idProduto): bool
    {
        if ($idProduto !== null) {
            $p = $this->db->prepare('SELECT 1 FROM produtos WHERE id = ? AND id_restaurante = ? AND ativo = 1');
            $p->execute([$idProduto, $this->idRestaurante]);
            if ($p->fetchColumn() === false) {
                return false;
            }
        }
        $u = $this->db->prepare('UPDATE ifood_itens SET id_produto = ?, vinculo = ?, enviado_status = NULL, enviado_estoque = NULL, ultimo_erro = NULL WHERE id = ? AND id_restaurante = ?');
        $u->execute([$idProduto, $idProduto !== null ? 'manual' : null, $idItem, $this->idRestaurante]);
        return $u->rowCount() === 1 || $this->existe($idItem);
    }

    /** @return list<array<string,mixed>> itens com o produto ligado e o que o sistema quer mostrar no iFood */
    public function itens(): array
    {
        $q = $this->db->prepare('SELECT i.id, i.ifood_item_id, i.nome, i.codigo_externo, i.categoria, i.preco, i.status_ifood, i.id_produto, i.vinculo,
                i.enviado_status, i.enviado_estoque, i.sincronizado_em, i.ultimo_erro, p.nome AS produto_nome
            FROM ifood_itens i LEFT JOIN produtos p ON p.id = i.id_produto AND p.id_restaurante = i.id_restaurante
            WHERE i.id_restaurante = ? ORDER BY i.categoria, i.nome, i.id');
        $q->execute([$this->idRestaurante]);
        $itens = $q->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_values(array_filter(array_map(static fn (array $i): int => (int) $i['id_produto'], $itens)));
        $disp = $ids !== [] ? (new DisponibilidadeProdutos($this->db))->verificar($this->idRestaurante, $ids) : [];
        foreach ($itens as &$i) {
            $d = $i['id_produto'] !== null ? ($disp[(int) $i['id_produto']] ?? null) : null;
            $i['desejado'] = $d === null ? null : (self::vende($d) ? 'AVAILABLE' : 'UNAVAILABLE');
            $i['porcoes'] = $d['porcoes'] ?? null;
        }
        unset($i);
        return $itens;
    }

    /** @return list<array{id:int,nome:string}> produtos ativos para escolher na ligacao */
    public function produtos(): array
    {
        $q = $this->db->prepare('SELECT id, nome FROM produtos WHERE id_restaurante = ? AND ativo = 1 ORDER BY nome');
        $q->execute([$this->idRestaurante]);
        return array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'nome' => (string) $p['nome']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Envia ao iFood a disponibilidade (e a quantidade, quando o produto controla estoque) dos itens ligados.
     * So envia o que mudou desde o ultimo envio aceito; $forcar envia tudo de novo.
     * @return array{enviados:int, erros:int, ultimo_erro:?string}
     */
    public function sincronizar(IfoodConta $conta, bool $forcar = false): array
    {
        $loja = $conta->loja();
        if ($loja === null) {
            return ['enviados' => 0, 'erros' => 0, 'ultimo_erro' => null];
        }
        $q = $this->db->prepare('SELECT id, ifood_item_id, ifood_produto_id, id_produto, enviado_status, enviado_estoque FROM ifood_itens WHERE id_restaurante = ? AND id_produto IS NOT NULL ORDER BY id');
        $q->execute([$this->idRestaurante]);
        $itens = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($itens === []) {
            return ['enviados' => 0, 'erros' => 0, 'ultimo_erro' => null];
        }
        $disp = (new DisponibilidadeProdutos($this->db))->verificar($this->idRestaurante, array_map(static fn (array $i): int => (int) $i['id_produto'], $itens));
        $token = null;
        $enviados = 0;
        $erros = 0;
        $ultimoErro = null;
        $agora = date('Y-m-d H:i:s');
        foreach ($itens as $item) {
            $d = $disp[(int) $item['id_produto']] ?? ['disponivel' => false, 'porcoes' => 0];
            $status = self::vende($d) ? 'AVAILABLE' : 'UNAVAILABLE';
            $quantidade = $d['porcoes'] !== null && $item['ifood_produto_id'] !== null ? min((int) $d['porcoes'], 9999) : null;
            $mudouStatus = $forcar || $item['enviado_status'] !== $status;
            $mudouQtd = $quantidade !== null && ($forcar || $item['enviado_estoque'] === null || (int) $item['enviado_estoque'] !== $quantidade);
            if (!$mudouStatus && !$mudouQtd) {
                continue;
            }
            try {
                $token ??= $conta->token();
                $erroQtd = null;
                if ($mudouQtd) {
                    try {
                        $conta->api()->estoqueProduto($token, $loja, (string) $item['ifood_produto_id'], (int) $quantidade);
                    } catch (IntegracaoErro $e) {
                        if ($e->getCode() < 400 || $e->getCode() >= 500 || $e->getCode() === 401 || $e->getCode() === 429) {
                            throw $e;
                        }
                        // A loja pode nao usar o controle de quantidade do iFood: segue so com a disponibilidade.
                        $erroQtd = 'O iFood não aceitou a quantidade; só a disponibilidade é enviada.';
                    }
                }
                if ($mudouStatus) {
                    $conta->api()->disponibilidadeItem($token, $loja, (string) $item['ifood_item_id'], $status === 'AVAILABLE');
                }
                $this->db->prepare('UPDATE ifood_itens SET enviado_status = ?, enviado_estoque = ?, status_ifood = ?, sincronizado_em = ?, ultimo_erro = ? WHERE id = ? AND id_restaurante = ?')
                    ->execute([$status, $quantidade, $status, $agora, $erroQtd, (int) $item['id'], $this->idRestaurante]);
                $enviados++;
            } catch (IntegracaoErro $e) {
                $erros++;
                $ultimoErro = $e->getMessage();
                $this->db->prepare('UPDATE ifood_itens SET ultimo_erro = ? WHERE id = ? AND id_restaurante = ?')
                    ->execute([mb_substr($e->getMessage(), 0, 255), (int) $item['id'], $this->idRestaurante]);
                if ($e->getCode() === 401 || $e->getCode() === 429 || $e->getCode() === 0) {
                    break; // acesso recusado, limite de chamadas ou iFood fora: tenta na proxima rodada
                }
            }
        }
        return ['enviados' => $enviados, 'erros' => $erros, 'ultimo_erro' => $ultimoErro];
    }

    /** @param array{disponivel:bool, porcoes:?int} $d */
    private static function vende(array $d): bool
    {
        return $d['disponivel'] && ($d['porcoes'] === null || $d['porcoes'] > 0);
    }

    private function produtoParecido(?string $codigo, string $nome): ?int
    {
        if ($codigo !== null && $codigo !== '') {
            $q = $this->db->prepare('SELECT id FROM produtos WHERE id_restaurante = ? AND codigo = ? AND ativo = 1');
            $q->execute([$this->idRestaurante, $codigo]);
            $id = $q->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        $nome = trim($nome);
        if ($nome === '') {
            return null;
        }
        $q = $this->db->prepare('SELECT id FROM produtos WHERE id_restaurante = ? AND LOWER(nome) = ? AND ativo = 1');
        $q->execute([$this->idRestaurante, mb_strtolower($nome)]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    private function existe(int $idItem): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM ifood_itens WHERE id = ? AND id_restaurante = ?');
        $q->execute([$idItem, $this->idRestaurante]);
        return $q->fetchColumn() !== false;
    }
}
