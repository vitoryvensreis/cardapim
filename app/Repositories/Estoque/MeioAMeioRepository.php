<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use PDO;

/**
 * Produtos Meio a Meio do restaurante e os sabores de cada um.
 * Sabor = produto ativo, vendido por unidade, que nao e combo nem Meio a Meio (e nao e o proprio produto).
 */
final class MeioAMeioRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Configuracao dos produtos pedidos que sao Meio a Meio (produto que nao e fica de fora).
     * @param list<int> $idsProdutos
     * @return array<int, array{minimo:int,maximo:int,regra:string,id_categoria:?int,sabores:list<int>}>
     */
    public function configuracoes(int $idRestaurante, array $idsProdutos): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsProdutos), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->prepare("SELECT id_produto, minimo, maximo, regra, id_categoria FROM produto_meio_a_meio WHERE id_restaurante = ? AND id_produto IN ($marcas)");
        $q->execute([$idRestaurante, ...$ids]);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $saida[(int) $r['id_produto']] = [
                'minimo' => (int) $r['minimo'], 'maximo' => (int) $r['maximo'], 'regra' => (string) $r['regra'],
                'id_categoria' => $r['id_categoria'] !== null ? (int) $r['id_categoria'] : null, 'sabores' => [],
            ];
        }
        if ($saida === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($saida), '?'));
        $q = $this->db->prepare("SELECT id_produto, id_sabor FROM produto_meio_sabores WHERE id_restaurante = ? AND id_produto IN ($marcas) ORDER BY id_sabor");
        $q->execute([$idRestaurante, ...array_keys($saida)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $saida[(int) $r['id_produto']]['sabores'][] = (int) $r['id_sabor'];
        }
        return $saida;
    }

    /**
     * Sabores que podem ser escolhidos agora em cada Meio a Meio: os produtos da categoria ou os escolhidos,
     * ativos, por unidade, que nao sao combo nem Meio a Meio. Em ordem de nome.
     * @param array<int, array{id_categoria:?int,sabores:list<int>}> $configs
     * @return array<int, list<array{id:int,nome:string,descricao:string,preco:string,codigo:?string,imagem_path:?string}>>
     */
    public function sabores(int $idRestaurante, array $configs): array
    {
        $categorias = [];
        $avulsos = [];
        foreach ($configs as $c) {
            if ($c['id_categoria'] !== null) {
                $categorias[$c['id_categoria']] = true;
            } else {
                foreach ($c['sabores'] as $s) {
                    $avulsos[$s] = true;
                }
            }
        }
        if ($categorias === [] && $avulsos === []) {
            return array_map(static fn (): array => [], $configs);
        }
        $cond = [];
        $params = [$idRestaurante];
        if ($categorias !== []) {
            $cond[] = 'p.id_categoria IN (' . implode(',', array_fill(0, count($categorias), '?')) . ')';
            array_push($params, ...array_keys($categorias));
        }
        if ($avulsos !== []) {
            $cond[] = 'p.id IN (' . implode(',', array_fill(0, count($avulsos), '?')) . ')';
            array_push($params, ...array_keys($avulsos));
        }
        $q = $this->db->prepare("SELECT p.id, p.nome, p.descricao, p.preco, p.codigo, p.imagem_path, p.id_categoria FROM produtos p
            WHERE p.id_restaurante = ? AND p.ativo = 1 AND p.unidade_venda = 'un' AND (" . implode(' OR ', $cond) . ')
              AND NOT EXISTS (SELECT 1 FROM produto_meio_a_meio m WHERE m.id_produto = p.id)'
            . $this->semCombo() . ' ORDER BY p.nome, p.id');
        $q->execute($params);
        $linhas = $q->fetchAll(PDO::FETCH_ASSOC);
        $saida = [];
        foreach ($configs as $idMeio => $c) {
            $saida[$idMeio] = [];
            foreach ($linhas as $r) {
                $id = (int) $r['id'];
                $entra = $c['id_categoria'] !== null ? (int) ($r['id_categoria'] ?? 0) === $c['id_categoria'] : in_array($id, $c['sabores'], true);
                if ($entra && $id !== $idMeio) {
                    $saida[$idMeio][] = [
                        'id' => $id, 'nome' => (string) $r['nome'], 'descricao' => (string) ($r['descricao'] ?? ''), 'preco' => (string) $r['preco'],
                        'codigo' => $r['codigo'] !== null && $r['codigo'] !== '' ? (string) $r['codigo'] : null,
                        'imagem_path' => $r['imagem_path'] !== null && $r['imagem_path'] !== '' ? (string) $r['imagem_path'] : null,
                    ];
                }
            }
        }
        return $saida;
    }

    /**
     * Produtos que podem ser sabor de um Meio a Meio (para o formulario), sem o proprio produto.
     * @return list<array{id:int,nome:string,preco:string,id_categoria:?int}>
     */
    public function candidatos(int $idRestaurante, ?int $menos): array
    {
        $q = $this->db->prepare("SELECT p.id, p.nome, p.preco, p.id_categoria FROM produtos p
            WHERE p.id_restaurante = ? AND p.ativo = 1 AND p.unidade_venda = 'un' AND p.id <> ?
              AND NOT EXISTS (SELECT 1 FROM produto_meio_a_meio m WHERE m.id_produto = p.id)" . $this->semCombo() . ' ORDER BY p.nome');
        $q->execute([$idRestaurante, $menos ?? 0]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'preco' => (string) $r['preco'],
            'id_categoria' => $r['id_categoria'] !== null ? (int) $r['id_categoria'] : null], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Grava (ou tira) o Meio a Meio do produto. Chamar dentro de uma transacao.
     * @param array{minimo:int,maximo:int,regra:string,id_categoria:?int,sabores:list<int>}|null $config null = nao e Meio a Meio
     */
    public function salvar(int $idRestaurante, int $idProduto, ?array $config): void
    {
        if ($config === null) {
            $this->db->prepare('DELETE FROM produto_meio_a_meio WHERE id_produto = ? AND id_restaurante = ?')->execute([$idProduto, $idRestaurante]);
            return;
        }
        $this->db->prepare('INSERT INTO produto_meio_a_meio (id_produto, id_restaurante, minimo, maximo, regra, id_categoria) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE minimo = VALUES(minimo), maximo = VALUES(maximo), regra = VALUES(regra), id_categoria = VALUES(id_categoria)')
            ->execute([$idProduto, $idRestaurante, $config['minimo'], $config['maximo'], $config['regra'], $config['id_categoria']]);
        $this->db->prepare('DELETE FROM produto_meio_sabores WHERE id_produto = ? AND id_restaurante = ?')->execute([$idProduto, $idRestaurante]);
        if ($config['id_categoria'] === null) {
            $ins = $this->db->prepare('INSERT INTO produto_meio_sabores (id_produto, id_sabor, id_restaurante) VALUES (?, ?, ?)');
            foreach (array_values(array_unique($config['sabores'])) as $s) {
                $ins->execute([$idProduto, $s, $idRestaurante]);
            }
        }
    }

    /** O produto e combo? (um produto nao pode ser combo e Meio a Meio ao mesmo tempo) */
    public function ehCombo(int $idRestaurante, int $idProduto): bool
    {
        if (!\Cardapim\Shared\Esquema::tem($this->db, 'combos')) {
            return false;
        }
        $q = $this->db->prepare('SELECT 1 FROM combos WHERE id_produto = ? AND id_restaurante = ?');
        $q->execute([$idProduto, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    private function semCombo(): string
    {
        return \Cardapim\Shared\Esquema::tem($this->db, 'combos') ? ' AND NOT EXISTS (SELECT 1 FROM combos c WHERE c.id_produto = p.id)' : '';
    }
}
