<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use PDO;

/**
 * Grupos de complementos, opcoes, fichas tecnicas das opcoes e ligacao com os produtos.
 * Toda consulta filtra por id_restaurante (e as FKs compostas impedem misturar restaurantes).
 */
final class ComplementoRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Grupos ativos com as opcoes ativas e quantos produtos usam cada grupo.
     * @return list<array{id:int, nome:string, minimo:int, maximo:int, ordem:int, produtos:int, opcoes:list<array<string,mixed>>}>
     */
    public function grupos(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT g.id, g.nome, g.minimo, g.maximo, g.ordem,
                (SELECT COUNT(*) FROM produto_complemento_grupos pg JOIN produtos p ON p.id = pg.id_produto AND p.id_restaurante = pg.id_restaurante
                 WHERE pg.id_grupo = g.id AND pg.id_restaurante = g.id_restaurante AND p.ativo = 1) AS produtos
            FROM complemento_grupos g WHERE g.id_restaurante = ? AND g.ativo = 1 ORDER BY g.ordem, g.nome, g.id');
        $q->execute([$idRestaurante]);
        $grupos = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $grupos[(int) $g['id']] = ['id' => (int) $g['id'], 'nome' => (string) $g['nome'], 'minimo' => (int) $g['minimo'], 'maximo' => (int) $g['maximo'],
                'ordem' => (int) $g['ordem'], 'produtos' => (int) $g['produtos'], 'opcoes' => []];
        }
        if ($grupos !== []) {
            foreach ($this->opcoesDosGrupos($idRestaurante, array_keys($grupos)) as $o) {
                $grupos[$o['id_grupo']]['opcoes'][] = $o;
            }
        }
        return array_values($grupos);
    }

    /** @return array{id:int, nome:string, minimo:int, maximo:int, ordem:int, opcoes:list<array<string,mixed>>}|null */
    public function grupo(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT id, nome, minimo, maximo, ordem FROM complemento_grupos WHERE id = ? AND id_restaurante = ? AND ativo = 1');
        $q->execute([$id, $idRestaurante]);
        $g = $q->fetch(PDO::FETCH_ASSOC);
        if ($g === false) {
            return null;
        }
        return ['id' => (int) $g['id'], 'nome' => (string) $g['nome'], 'minimo' => (int) $g['minimo'], 'maximo' => (int) $g['maximo'], 'ordem' => (int) $g['ordem'],
            'opcoes' => $this->opcoesDosGrupos($idRestaurante, [(int) $g['id']])];
    }

    /**
     * Opcoes ativas dos grupos (com a ficha tecnica de cada uma), em ordem.
     * @param list<int> $idsGrupos
     * @return list<array{id:int, id_grupo:int, nome:string, descricao:?string, preco:string, codigo:?string, imagem_path:?string, disponivel:bool, ordem:int, ficha:list<array{id_insumo:int, quantidade:string}>}>
     */
    public function opcoesDosGrupos(int $idRestaurante, array $idsGrupos): array
    {
        $idsGrupos = array_values(array_unique(array_map('intval', $idsGrupos)));
        if ($idsGrupos === []) {
            return [];
        }
        $marc = implode(',', array_fill(0, count($idsGrupos), '?'));
        $q = $this->db->prepare("SELECT id, id_grupo, nome, descricao, preco, codigo, imagem_path, disponivel, ordem FROM complementos
            WHERE id_restaurante = ? AND ativo = 1 AND id_grupo IN ($marc) ORDER BY id_grupo, ordem, id");
        $q->execute([$idRestaurante, ...$idsGrupos]);
        $opcoes = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $opcoes[(int) $o['id']] = [
                'id' => (int) $o['id'], 'id_grupo' => (int) $o['id_grupo'], 'nome' => (string) $o['nome'],
                'descricao' => $o['descricao'] !== null ? (string) $o['descricao'] : null, 'preco' => (string) $o['preco'],
                'codigo' => $o['codigo'] !== null ? (string) $o['codigo'] : null, 'imagem_path' => $o['imagem_path'] !== null ? (string) $o['imagem_path'] : null,
                'disponivel' => (int) $o['disponivel'] === 1, 'ordem' => (int) $o['ordem'], 'ficha' => [],
            ];
        }
        if ($opcoes !== []) {
            $marc = implode(',', array_fill(0, count($opcoes), '?'));
            $f = $this->db->prepare("SELECT id_complemento, id_insumo, quantidade FROM complemento_insumos WHERE id_restaurante = ? AND id_complemento IN ($marc) ORDER BY id");
            $f->execute([$idRestaurante, ...array_keys($opcoes)]);
            foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $opcoes[(int) $l['id_complemento']]['ficha'][] = ['id_insumo' => (int) $l['id_insumo'], 'quantidade' => (string) $l['quantidade']];
            }
        }
        return array_values($opcoes);
    }

    /**
     * Grupos (com opcoes) de varios produtos de uma vez, na ordem escolhida em cada produto.
     * @param list<int> $idsProdutos
     * @return array<int, list<array{id:int, nome:string, minimo:int, maximo:int, opcoes:list<array<string,mixed>>}>> id_produto => grupos
     */
    public function gruposDosProdutos(int $idRestaurante, array $idsProdutos): array
    {
        $idsProdutos = array_values(array_unique(array_map('intval', $idsProdutos)));
        if ($idsProdutos === []) {
            return [];
        }
        $marc = implode(',', array_fill(0, count($idsProdutos), '?'));
        $q = $this->db->prepare("SELECT pg.id_produto, g.id, g.nome, g.minimo, g.maximo FROM produto_complemento_grupos pg
            JOIN complemento_grupos g ON g.id = pg.id_grupo AND g.id_restaurante = pg.id_restaurante
            WHERE pg.id_restaurante = ? AND g.ativo = 1 AND pg.id_produto IN ($marc) ORDER BY pg.id_produto, pg.ordem, g.ordem, g.id");
        $q->execute([$idRestaurante, ...$idsProdutos]);
        $linhas = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($linhas === []) {
            return [];
        }
        $opcoes = [];
        foreach ($this->opcoesDosGrupos($idRestaurante, array_map(static fn (array $l): int => (int) $l['id'], $linhas)) as $o) {
            $opcoes[$o['id_grupo']][] = $o;
        }
        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['id_produto']][] = ['id' => (int) $l['id'], 'nome' => (string) $l['nome'], 'minimo' => (int) $l['minimo'], 'maximo' => (int) $l['maximo'],
                'opcoes' => $opcoes[(int) $l['id']] ?? []];
        }
        return $saida;
    }

    /** @return list<int> ids dos grupos do produto, em ordem */
    public function idsGruposDoProduto(int $idRestaurante, int $idProduto): array
    {
        $q = $this->db->prepare('SELECT pg.id_grupo FROM produto_complemento_grupos pg JOIN complemento_grupos g ON g.id = pg.id_grupo AND g.id_restaurante = pg.id_restaurante
            WHERE pg.id_produto = ? AND pg.id_restaurante = ? AND g.ativo = 1 ORDER BY pg.ordem');
        $q->execute([$idProduto, $idRestaurante]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Substitui os grupos do produto (na ordem dada). Chamar dentro de transacao. @param list<int> $idsGrupos */
    public function salvarGruposDoProduto(int $idRestaurante, int $idProduto, array $idsGrupos): void
    {
        $this->db->prepare('DELETE FROM produto_complemento_grupos WHERE id_produto = ? AND id_restaurante = ?')->execute([$idProduto, $idRestaurante]);
        $ins = $this->db->prepare('INSERT INTO produto_complemento_grupos (id_produto, id_grupo, id_restaurante, ordem) VALUES (?, ?, ?, ?)');
        foreach (array_values(array_unique($idsGrupos)) as $ordem => $idGrupo) {
            $ins->execute([$idProduto, $idGrupo, $idRestaurante, $ordem]);
        }
    }

    public function removerLigacoesDoProduto(int $idRestaurante, int $idProduto): void
    {
        $this->db->prepare('DELETE FROM produto_complemento_grupos WHERE id_produto = ? AND id_restaurante = ?')->execute([$idProduto, $idRestaurante]);
    }

    /** @param list<int> $ids @return list<int> os que existem, estao ativos e sao deste restaurante */
    public function gruposValidos(int $idRestaurante, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $marc = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->prepare("SELECT id FROM complemento_grupos WHERE id_restaurante = ? AND ativo = 1 AND id IN ($marc)");
        $q->execute([$idRestaurante, ...$ids]);
        $validos = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter($ids, static fn (int $i): bool => in_array($i, $validos, true)));
    }

    public function criarGrupo(int $idRestaurante, string $nome, int $minimo, int $maximo, int $ordem): int
    {
        $this->db->prepare('INSERT INTO complemento_grupos (id_restaurante, nome, minimo, maximo, ordem) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idRestaurante, $nome, $minimo, $maximo, $ordem]);
        return (int) $this->db->lastInsertId();
    }

    public function atualizarGrupo(int $idRestaurante, int $id, string $nome, int $minimo, int $maximo): void
    {
        $this->db->prepare('UPDATE complemento_grupos SET nome = ?, minimo = ?, maximo = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([$nome, $minimo, $maximo, $id, $idRestaurante]);
    }

    /** Arquiva o grupo (some de todos os produtos e do cardapio). Pedidos antigos guardam a copia do nome. */
    public function arquivarGrupo(int $idRestaurante, int $id): void
    {
        $this->db->prepare('DELETE FROM produto_complemento_grupos WHERE id_grupo = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
        $this->db->prepare('UPDATE complementos SET ativo = 0, disponivel = 0, codigo = NULL WHERE id_grupo = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
        $this->db->prepare('UPDATE complemento_grupos SET ativo = 0 WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
    }

    /** @param array{nome:string, descricao:?string, preco:string, codigo:?string, imagem_path:?string, disponivel:int, ordem:int} $d */
    public function criarOpcao(int $idRestaurante, int $idGrupo, array $d): int
    {
        $this->db->prepare('INSERT INTO complementos (id_restaurante, id_grupo, nome, descricao, preco, codigo, imagem_path, disponivel, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idRestaurante, $idGrupo, $d['nome'], $d['descricao'], $d['preco'], $d['codigo'], $d['imagem_path'], $d['disponivel'], $d['ordem']]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{nome:string, descricao:?string, preco:string, codigo:?string, imagem_path:?string, disponivel:int, ordem:int} $d */
    public function atualizarOpcao(int $idRestaurante, int $id, array $d): void
    {
        $this->db->prepare('UPDATE complementos SET nome = ?, descricao = ?, preco = ?, codigo = ?, imagem_path = ?, disponivel = ?, ordem = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([$d['nome'], $d['descricao'], $d['preco'], $d['codigo'], $d['imagem_path'], $d['disponivel'], $d['ordem'], $id, $idRestaurante]);
    }

    public function arquivarOpcao(int $idRestaurante, int $id): void
    {
        $this->db->prepare('UPDATE complementos SET ativo = 0, disponivel = 0, codigo = NULL WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
    }

    public function definirDisponivel(int $idRestaurante, int $id, bool $disponivel): void
    {
        $this->db->prepare('UPDATE complementos SET disponivel = ? WHERE id = ? AND id_restaurante = ? AND ativo = 1')->execute([$disponivel ? 1 : 0, $id, $idRestaurante]);
    }

    /** @return array<string,mixed>|null opcao ativa com o nome do grupo */
    public function opcao(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT c.id, c.id_grupo, c.nome, c.preco, c.disponivel, c.imagem_path, g.nome AS grupo FROM complementos c
            JOIN complemento_grupos g ON g.id = c.id_grupo AND g.id_restaurante = c.id_restaurante WHERE c.id = ? AND c.id_restaurante = ? AND c.ativo = 1');
        $q->execute([$id, $idRestaurante]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        return $o === false ? null : $o;
    }

    /** Substitui a ficha tecnica da opcao. Chamar dentro de transacao. @param array<int,string> $itens id_insumo => quantidade */
    public function salvarFicha(int $idRestaurante, int $idComplemento, array $itens): void
    {
        $this->db->prepare('DELETE FROM complemento_insumos WHERE id_complemento = ? AND id_restaurante = ?')->execute([$idComplemento, $idRestaurante]);
        $ins = $this->db->prepare('INSERT INTO complemento_insumos (id_restaurante, id_complemento, id_insumo, quantidade) VALUES (?, ?, ?, ?)');
        foreach ($itens as $idInsumo => $quantidade) {
            $ins->execute([$idRestaurante, $idComplemento, $idInsumo, $quantidade]);
        }
    }

    public function codigoEmUso(int $idRestaurante, string $codigo, ?int $ignorarId): bool
    {
        $q = $this->db->prepare('SELECT id FROM complementos WHERE id_restaurante = ? AND codigo = ?');
        $q->execute([$idRestaurante, $codigo]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $ignorarId) {
                return true;
            }
        }
        return false;
    }

    /** Nomes dos complementos que usam o insumo (aviso antes de excluir o insumo). @return list<string> */
    public function queUsamInsumo(int $idRestaurante, int $idInsumo): array
    {
        $q = $this->db->prepare('SELECT c.nome FROM complemento_insumos ci JOIN complementos c ON c.id = ci.id_complemento AND c.id_restaurante = ci.id_restaurante
            WHERE ci.id_insumo = ? AND ci.id_restaurante = ? AND c.ativo = 1 ORDER BY c.nome');
        $q->execute([$idInsumo, $idRestaurante]);
        return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function proximaOrdemGrupo(int $idRestaurante): int
    {
        $q = $this->db->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM complemento_grupos WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        return min(65000, (int) $q->fetchColumn());
    }
}
