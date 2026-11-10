<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use PDO;

/** Produtos do cardapio e suas fichas tecnicas. Toda consulta filtra por id_restaurante. */
final class ProdutoRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listar(int $idRestaurante): array
    {
        $query = $this->db->prepare(
            'SELECT p.id, p.nome, p.preco, p.codigo, p.id_categoria, c.nome AS categoria, p.controla_estoque, p.disponivel, p.ativo
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.id_categoria AND c.id_restaurante = p.id_restaurante
             WHERE p.id_restaurante = ? AND p.ativo = 1
             ORDER BY c.ordem IS NULL, c.ordem, c.nome, p.ordem, p.nome'
        );
        $query->execute([$idRestaurante]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $query = $this->db->prepare(
            'SELECT p.id, p.nome, p.descricao, p.preco, p.codigo, p.id_categoria, c.nome AS categoria, p.controla_estoque, p.disponivel, p.ativo
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.id_categoria AND c.id_restaurante = p.id_restaurante
             WHERE p.id = ? AND p.id_restaurante = ?'
        );
        $query->execute([$id, $idRestaurante]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array{id_insumo:int,nome:string,unidade:string,quantidade:string}> */
    public function ficha(int $idRestaurante, int $idProduto): array
    {
        $query = $this->db->prepare(
            'SELECT pi.id_insumo, i.nome, i.unidade, pi.quantidade
             FROM produto_insumos pi
             JOIN insumos i ON i.id = pi.id_insumo AND i.id_restaurante = pi.id_restaurante
             WHERE pi.id_produto = ? AND pi.id_restaurante = ?
             ORDER BY i.nome'
        );
        $query->execute([$idProduto, $idRestaurante]);
        return array_map(static fn (array $r): array => [
            'id_insumo' => (int) $r['id_insumo'], 'nome' => (string) $r['nome'], 'unidade' => (string) $r['unidade'], 'quantidade' => (string) $r['quantidade'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function nomeEmUso(int $idRestaurante, string $nome, ?int $ignorarId = null): bool
    {
        return $this->emUso('LOWER(nome) = LOWER(?)', $idRestaurante, $nome, $ignorarId);
    }

    public function codigoEmUso(int $idRestaurante, string $codigo, ?int $ignorarId = null): bool
    {
        return $this->emUso('codigo = ?', $idRestaurante, $codigo, $ignorarId);
    }

    /** @param array{nome:string,descricao:?string,preco:string,codigo:?string,id_categoria:?int,controla_estoque:int,disponivel:int} $d */
    public function criar(int $idRestaurante, array $d): int
    {
        $this->db->prepare(
            'INSERT INTO produtos (id_restaurante, id_categoria, nome, descricao, preco, codigo, controla_estoque, disponivel)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$idRestaurante, $d['id_categoria'], $d['nome'], $d['descricao'], $d['preco'], $d['codigo'], $d['controla_estoque'], $d['disponivel']]);
        $id = $this->db->prepare('SELECT id FROM produtos WHERE id_restaurante = ? AND nome = ?');
        $id->execute([$idRestaurante, $d['nome']]);
        return (int) $id->fetchColumn();
    }

    /** @param array{nome:string,descricao:?string,preco:string,codigo:?string,id_categoria:?int,controla_estoque:int,disponivel:int} $d */
    public function atualizar(int $idRestaurante, int $id, array $d): void
    {
        $this->db->prepare(
            'UPDATE produtos SET id_categoria = ?, nome = ?, descricao = ?, preco = ?, codigo = ?, controla_estoque = ?, disponivel = ?
             WHERE id = ? AND id_restaurante = ?'
        )->execute([$d['id_categoria'], $d['nome'], $d['descricao'], $d['preco'], $d['codigo'], $d['controla_estoque'], $d['disponivel'], $id, $idRestaurante]);
    }

    public function definirDisponivel(int $idRestaurante, int $id, bool $disponivel): void
    {
        $this->db->prepare('UPDATE produtos SET disponivel = ? WHERE id = ? AND id_restaurante = ?')->execute([$disponivel ? 1 : 0, $id, $idRestaurante]);
    }

    /**
     * Substitui a ficha tecnica inteira. Chamar dentro de transacao.
     * @param array<int,string> $itens id_insumo => quantidade decimal ("0.080")
     */
    public function salvarFicha(int $idRestaurante, int $idProduto, array $itens): void
    {
        $this->db->prepare('DELETE FROM produto_insumos WHERE id_produto = ? AND id_restaurante = ?')->execute([$idProduto, $idRestaurante]);
        $insert = $this->db->prepare('INSERT INTO produto_insumos (id_restaurante, id_produto, id_insumo, quantidade) VALUES (?, ?, ?, ?)');
        foreach ($itens as $idInsumo => $quantidade) {
            $insert->execute([$idRestaurante, $idProduto, $idInsumo, $quantidade]);
        }
    }

    /** Ja foi vendido? (produto com pedidos nao e apagado: e arquivado para manter o historico) */
    public function temPedidos(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM pedido_itens WHERE id_produto = ? AND id_restaurante = ? LIMIT 1');
        $q->execute([$id, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    /** Arquiva: some do cardapio e do painel, libera o nome e o codigo, e os pedidos antigos continuam intactos. */
    public function arquivar(int $idRestaurante, int $id, string $nome): void
    {
        $sufixo = ' (arquivado ' . $id . ')';
        $this->db->prepare('UPDATE produtos SET ativo = 0, disponivel = 0, codigo = NULL, nome = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ? AND id_restaurante = ?')
            ->execute([mb_substr($nome, 0, 150 - mb_strlen($sufixo)) . $sufixo, $id, $idRestaurante]);
    }

    /** Exclui produto e ficha. Chamar dentro de transacao. */
    public function excluir(int $idRestaurante, int $id): void
    {
        $this->db->prepare('DELETE FROM produto_insumos WHERE id_produto = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
        $this->db->prepare('DELETE FROM produtos WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
    }

    private function emUso(string $condicao, int $idRestaurante, string $valor, ?int $ignorarId): bool
    {
        $query = $this->db->prepare('SELECT id FROM produtos WHERE id_restaurante = ? AND ' . $condicao);
        $query->execute([$idRestaurante, $valor]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $ignorarId) {
                return true;
            }
        }
        return false;
    }
}
