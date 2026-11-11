<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/**
 * Planos da plataforma. Publico = ativo e visivel (pagina inicial, cadastro e troca de plano).
 * Privado = so com o link de convite (codigo_convite), ex.: parceiro com plano vitalicio gratuito.
 * O preco e o total do periodo; a assinatura copia valor e periodo (mudar o plano nao muda quem ja assinou).
 */
final class PlanoRepository
{
    private const CAMPOS = 'id, nome, descricao, valor, periodo, ativo, visibilidade, codigo_convite';
    private const ORDEM = "ORDER BY FIELD(periodo, 'mensal', 'semestral', 'anual', 'vitalicio'), valor ASC, id ASC";

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Valida novamente o plano escolhido pela URL, sem confiar no id da interface.
     * @return array<string,mixed>|null
     */
    public function buscarPublicoPorId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM planos WHERE id = ? AND ativo = 1 AND visibilidade = 1 LIMIT 1');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Plano do link de convite (privado ou publico), so se estiver aceitando assinaturas. @return array<string,mixed>|null */
    public function buscarPorConvite(string $codigo): ?array
    {
        if (preg_match('/\A[a-f0-9]{24}\z/', $codigo) !== 1) {
            return null;
        }
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM planos WHERE codigo_convite = ? AND ativo = 1 LIMIT 1');
        $query->execute([$codigo]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listarPublicos(): array
    {
        return $this->db->query('SELECT ' . self::CAMPOS . ' FROM planos WHERE ativo = 1 AND visibilidade = 1 ' . self::ORDEM)->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ---------- Administracao (painel da plataforma) ---------- */

    /** Todos os planos com quantos restaurantes estao em cada um. @return list<array<string,mixed>> */
    public function listarTodos(): array
    {
        return $this->db->query('SELECT p.id, p.nome, p.descricao, p.valor, p.periodo, p.ativo, p.visibilidade, p.codigo_convite,
            (SELECT COUNT(*) FROM assinaturas a WHERE a.plano_id = p.id AND a.status IN (\'aguardando\', \'ativa\', \'cancelada\')) AS restaurantes
            FROM planos p ORDER BY p.ativo DESC, FIELD(p.periodo, \'mensal\', \'semestral\', \'anual\', \'vitalicio\'), p.valor ASC, p.id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM planos WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function nomeEmUso(string $nome, string $periodo, ?int $exceto): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM planos WHERE LOWER(nome) = LOWER(?) AND periodo = ? AND id <> ? LIMIT 1');
        $query->execute([$nome, $periodo, $exceto ?? 0]);
        return $query->fetchColumn() !== false;
    }

    /** @param array{nome:string,descricao:?string,valor:string,periodo:string,ativo:int,visibilidade:int,codigo_convite:?string} $d */
    public function criar(array $d): int
    {
        $this->db->prepare('INSERT INTO planos (nome, descricao, valor, periodo, ativo, visibilidade, codigo_convite) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['nome'], $d['descricao'], $d['valor'], $d['periodo'], $d['ativo'], $d['visibilidade'], $d['codigo_convite']]);
        $id = (int) $this->db->lastInsertId();
        if ($id < 1) {
            $q = $this->db->prepare('SELECT id FROM planos WHERE nome = ? AND periodo = ?');
            $q->execute([$d['nome'], $d['periodo']]);
            $id = (int) $q->fetchColumn();
        }
        return $id;
    }

    /** @param array{nome:string,descricao:?string,valor:string,periodo:string,ativo:int,visibilidade:int,codigo_convite:?string} $d */
    public function atualizar(int $id, array $d): void
    {
        $this->db->prepare('UPDATE planos SET nome = ?, descricao = ?, valor = ?, periodo = ?, ativo = ?, visibilidade = ?, codigo_convite = ? WHERE id = ?')
            ->execute([$d['nome'], $d['descricao'], $d['valor'], $d['periodo'], $d['ativo'], $d['visibilidade'], $d['codigo_convite'], $id]);
    }

    public function trocarConvite(int $id, string $codigo): void
    {
        $this->db->prepare('UPDATE planos SET codigo_convite = ? WHERE id = ?')->execute([$codigo, $id]);
    }

    public function mudarAtivo(int $id, bool $ativo): void
    {
        $this->db->prepare('UPDATE planos SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $id]);
    }

    /** Ja foi assinado alguma vez (ou e o plano de algum restaurante)? Entao nao pode ser apagado, so pausado. */
    public function emUso(int $id): bool
    {
        $query = $this->db->prepare('SELECT (SELECT COUNT(*) FROM assinaturas WHERE plano_id = ?) + (SELECT COUNT(*) FROM restaurantes WHERE plano_id = ?)');
        $query->execute([$id, $id]);
        return (int) $query->fetchColumn() > 0;
    }

    public function excluir(int $id): void
    {
        $this->db->prepare('DELETE FROM planos WHERE id = ?')->execute([$id]);
    }
}
