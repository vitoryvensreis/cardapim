<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Estoque;

use PDO;

/** Cadastro dos combos (itens obrigatorios, limite total) e das opcoes ligadas a produtos. Sempre por restaurante. */
final class ComboRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{ativo:bool, maximo:?int, fixos:list<array{id_produto:int,quantidade:int}>}
     */
    public function configuracao(int $idRestaurante, int $idProduto): array
    {
        $q = $this->db->prepare('SELECT maximo FROM combos WHERE id_produto = ? AND id_restaurante = ?');
        $q->execute([$idProduto, $idRestaurante]);
        $maximo = $q->fetchColumn();
        if ($maximo === false) {
            return ['ativo' => false, 'maximo' => null, 'fixos' => []];
        }
        $f = $this->db->prepare('SELECT id_produto, quantidade FROM combo_itens WHERE id_combo = ? AND id_restaurante = ? ORDER BY ordem, id_produto');
        $f->execute([$idProduto, $idRestaurante]);
        return [
            'ativo' => true,
            'maximo' => $maximo !== null ? (int) $maximo : null,
            'fixos' => array_map(static fn (array $r): array => ['id_produto' => (int) $r['id_produto'], 'quantidade' => (int) $r['quantidade']], $f->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** @return array<int,array{id_produto:?int,maximo:?int}> id_complemento => configuracao no combo */
    public function opcoes(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT id_complemento, id_produto, maximo FROM combo_opcoes WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        $saida = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $saida[(int) $r['id_complemento']] = [
                'id_produto' => $r['id_produto'] !== null ? (int) $r['id_produto'] : null,
                'maximo' => $r['maximo'] !== null ? (int) $r['maximo'] : null,
            ];
        }
        return $saida;
    }

    /**
     * Grava (ou desliga) o combo do produto. Chamar dentro de transacao.
     * @param list<array{id_produto:int,quantidade:int}> $fixos
     * @param array<int,array{id_produto:?int,maximo:?int}> $opcoes id_complemento => configuracao
     */
    public function salvar(int $idRestaurante, int $idProduto, bool $ativo, ?int $maximo, array $fixos, array $opcoes): void
    {
        $this->db->prepare('DELETE FROM combos WHERE id_produto = ? AND id_restaurante = ?')->execute([$idProduto, $idRestaurante]);
        if ($ativo) {
            $this->db->prepare('INSERT INTO combos (id_produto, id_restaurante, maximo) VALUES (?, ?, ?)')->execute([$idProduto, $idRestaurante, $maximo]);
            $ins = $this->db->prepare('INSERT INTO combo_itens (id_combo, id_produto, id_restaurante, quantidade, ordem) VALUES (?, ?, ?, ?, ?)');
            foreach ($fixos as $ordem => $f) {
                $ins->execute([$idProduto, $f['id_produto'], $idRestaurante, $f['quantidade'], $ordem]);
            }
        }
        $grava = $this->db->prepare('INSERT INTO combo_opcoes (id_complemento, id_restaurante, id_produto, maximo) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id_produto = VALUES(id_produto), maximo = VALUES(maximo)');
        $apaga = $this->db->prepare('DELETE FROM combo_opcoes WHERE id_complemento = ? AND id_restaurante = ?');
        $doRestaurante = $this->db->prepare('SELECT 1 FROM complementos WHERE id = ? AND id_restaurante = ?');
        foreach ($opcoes as $idComplemento => $o) {
            $doRestaurante->execute([$idComplemento, $idRestaurante]);
            if ($doRestaurante->fetchColumn() === false) {
                continue;
            }
            if ($o['id_produto'] === null && $o['maximo'] === null) {
                $apaga->execute([$idComplemento, $idRestaurante]);
            } else {
                $grava->execute([$idComplemento, $idRestaurante, $o['id_produto'], $o['maximo']]);
            }
        }
    }

    /**
     * Produtos que podem entrar num combo: ativos, vendidos por unidade e que nao sao combos.
     * @return list<array{id:int,nome:string,preco:string}>
     */
    public function produtosParaCombo(int $idRestaurante, ?int $menos): array
    {
        $q = $this->db->prepare("SELECT p.id, p.nome, p.preco FROM produtos p
            WHERE p.id_restaurante = ? AND p.ativo = 1 AND p.unidade_venda = 'un'
              AND NOT EXISTS (SELECT 1 FROM combos c WHERE c.id_produto = p.id) AND p.id <> ?
            ORDER BY p.nome");
        $q->execute([$idRestaurante, $menos ?? 0]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'preco' => (string) $r['preco']], $q->fetchAll(PDO::FETCH_ASSOC));
    }
}
