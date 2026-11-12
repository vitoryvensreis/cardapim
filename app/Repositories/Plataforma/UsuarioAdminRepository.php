<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use PDO;

/** Contas da plataforma (administradores e equipe de todos os restaurantes) vistas pela administracao. */
final class UsuarioAdminRepository
{
    public const CARGOS = ['adm_master', 'gerente', 'caixa', 'garcom', 'motoqueiro'];
    public const POR_PAGINA = 30;
    private const CAMPOS = 'u.id, u.id_restaurante, u.cargo, u.usuario, u.email, u.nome, u.sobrenome, u.cpf, u.whatsapp, u.ativo,
        u.ultimo_acesso, u.dois_fatores, u.criado_em, r.nome AS restaurante_nome, r.slug AS restaurante_slug, r.status_rest AS restaurante_status';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{itens:list<array<string,mixed>>, total:int} */
    public function listar(string $busca, ?string $cargo, ?int $idRestaurante, ?string $situacao, int $pagina): array
    {
        $onde = ['u.excluido_em IS NULL', 'u.cargo IN (' . implode(',', array_fill(0, count(self::CARGOS), '?')) . ')'];
        $params = self::CARGOS;
        if ($busca !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($busca)) . '%';
            $onde[] = "(LOWER(u.nome) LIKE ? ESCAPE '!' OR LOWER(u.sobrenome) LIKE ? ESCAPE '!' OR LOWER(u.usuario) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!'"
                . " OR LOWER(CONCAT(u.nome, ' ', u.sobrenome)) LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($cargo !== null) {
            $onde[] = 'u.cargo = ?';
            $params[] = $cargo;
        }
        if ($idRestaurante !== null) {
            $onde[] = 'u.id_restaurante = ?';
            $params[] = $idRestaurante;
        }
        if ($situacao === 'ativos') {
            $onde[] = 'u.ativo = 1';
        } elseif ($situacao === 'bloqueados') {
            $onde[] = 'u.ativo = 0';
        }
        $where = implode(' AND ', $onde);
        $total = $this->db->prepare('SELECT COUNT(*) FROM `user` u WHERE ' . $where);
        $total->execute($params);
        $q = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM `user` u LEFT JOIN restaurantes r ON r.id = u.id_restaurante
            WHERE ' . $where . " ORDER BY CASE u.cargo WHEN 'adm_master' THEN 0 ELSE 1 END, u.nome, u.sobrenome, u.id
            LIMIT " . self::POR_PAGINA . ' OFFSET ' . (max(0, $pagina - 1) * self::POR_PAGINA));
        $q->execute($params);
        return ['itens' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn()];
    }

    /** @return array<string,int> cargo => quantidade (contas nao excluidas) */
    public function contagemPorCargo(): array
    {
        $r = array_fill_keys(self::CARGOS, 0);
        foreach ($this->db->query('SELECT cargo, COUNT(*) AS n FROM `user` WHERE excluido_em IS NULL GROUP BY cargo')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if (isset($r[$l['cargo']])) {
                $r[(string) $l['cargo']] = (int) $l['n'];
            }
        }
        return $r;
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM `user` u LEFT JOIN restaurantes r ON r.id = u.id_restaurante
            WHERE u.id = ? AND u.excluido_em IS NULL AND u.cargo IN (' . implode(',', array_fill(0, count(self::CARGOS), '?')) . ')');
        $q->execute([$id, ...self::CARGOS]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Restaurantes para escolher no formulario. @return list<array{id:int,nome:string,slug:string}> */
    public function restaurantes(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'slug' => (string) $r['slug']],
            $this->db->query('SELECT id, nome, slug FROM restaurantes ORDER BY nome, id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function restauranteExiste(int $id): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM restaurantes WHERE id = ?');
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function admsAtivos(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `user` WHERE cargo = 'adm_master' AND ativo = 1 AND excluido_em IS NULL")->fetchColumn();
    }

    public function emUso(string $coluna, string $valor, ?int $ignorarId = null): bool
    {
        if (!in_array($coluna, ['usuario', 'email', 'cpf'], true)) {
            throw new \InvalidArgumentException('Coluna invalida.');
        }
        $q = $this->db->prepare('SELECT id FROM `user` WHERE LOWER(' . $coluna . ') = LOWER(?)');
        $q->execute([$valor]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $ignorarId) {
                return true;
            }
        }
        return false;
    }

    /** @param array{id_restaurante:?int,cargo:string,usuario:string,email:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string} $d */
    public function criar(array $d, string $hash): int
    {
        $this->db->prepare('INSERT INTO `user` (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome, cpf, whatsapp, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute([$d['id_restaurante'], $d['cargo'], $d['usuario'], $d['email'], $hash, $d['nome'], $d['sobrenome'], $d['cpf'], $d['whatsapp']]);
        $q = $this->db->prepare('SELECT id FROM `user` WHERE usuario = ?');
        $q->execute([$d['usuario']]);
        return (int) $q->fetchColumn();
    }

    /** @param array{id_restaurante:?int,cargo:string,usuario:string,email:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string} $d */
    public function atualizar(int $id, array $d): void
    {
        $this->db->prepare('UPDATE `user` SET id_restaurante = ?, cargo = ?, usuario = ?, email = ?, nome = ?, sobrenome = ?, cpf = ?, whatsapp = ? WHERE id = ? AND excluido_em IS NULL')
            ->execute([$d['id_restaurante'], $d['cargo'], $d['usuario'], $d['email'], $d['nome'], $d['sobrenome'], $d['cpf'], $d['whatsapp'], $id]);
    }

    public function definirSenha(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE `user` SET senha_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $this->db->prepare('UPDATE `user` SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $id]);
    }

    public function temHistorico(int $id): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM log_user WHERE id_user = ? LIMIT 1');
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function apagar(int $id): void
    {
        $this->db->prepare('DELETE FROM `user` WHERE id = ?')->execute([$id]);
    }

    /** Some do sistema, mas o id fica para a auditoria; dados pessoais apagados (LGPD), acesso bloqueado para sempre. */
    public function anonimizar(int $id, string $quando): void
    {
        $this->db->prepare("UPDATE `user` SET usuario = ?, email = ?, senha_hash = ?, cpf = NULL, whatsapp = NULL, nome = 'Conta removida', sobrenome = ?,
                ativo = 0, excluido_em = ?, ultimo_codigo_hash = NULL, ultimo_codigo_expira_em = NULL, dois_fatores = 'desativado', dois_fatores_segredo = NULL
            WHERE id = ?")
            ->execute(['removido_' . $id, 'removido+' . $id . '@cardapim.invalid', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), '#' . $id, $quando, $id]);
    }
}
