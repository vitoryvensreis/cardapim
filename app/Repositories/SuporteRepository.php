<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/** Chamados do suporte: o restaurante so ve os seus; a plataforma ve todos. */
final class SuporteRepository
{
    public const POR_PAGINA = 20;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Chamados do restaurante. $filtro: abertos | resolvidos | todos.
     * @return list<array<string,mixed>>
     */
    public function doRestaurante(int $idRestaurante, string $filtro): array
    {
        [$onde, $params] = self::filtroSituacao($filtro);
        $q = $this->db->prepare('SELECT c.*, (SELECT COUNT(*) FROM suporte_mensagens m WHERE m.id_chamado = c.id) AS mensagens
            FROM suporte_chamados c WHERE c.id_restaurante = ?' . $onde . ' ORDER BY c.ultima_mensagem_em DESC, c.id DESC LIMIT 200');
        $q->execute([$idRestaurante, ...$params]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,int> */
    public function contagemRestaurante(int $idRestaurante): array
    {
        $q = $this->db->prepare("SELECT SUM(CASE WHEN status <> 'resolvido' THEN 1 ELSE 0 END), SUM(CASE WHEN status = 'resolvido' THEN 1 ELSE 0 END), COUNT(*)
            FROM suporte_chamados WHERE id_restaurante = ?");
        $q->execute([$idRestaurante]);
        $r = $q->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];
        return ['abertos' => (int) $r[0], 'resolvidos' => (int) $r[1], 'todos' => (int) $r[2]];
    }

    /**
     * Todos os chamados (plataforma). $filtro: aguardando | abertos | resolvidos | todos.
     * @return array{itens:list<array<string,mixed>>, total:int}
     */
    public function daPlataforma(string $filtro, string $busca, int $pagina): array
    {
        [$onde, $params] = self::filtroSituacao($filtro);
        if ($busca !== '') {
            $termo = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($busca)) . '%';
            $onde .= " AND (LOWER(c.assunto) LIKE ? ESCAPE '!' OR LOWER(r.nome) LIKE ? ESCAPE '!'" . (ctype_digit($busca) ? ' OR c.id = ?' : '') . ')';
            array_push($params, $termo, $termo);
            if (ctype_digit($busca)) {
                $params[] = (int) $busca;
            }
        }
        $total = $this->db->prepare('SELECT COUNT(*) FROM suporte_chamados c JOIN restaurantes r ON r.id = c.id_restaurante WHERE 1 = 1' . $onde);
        $total->execute($params);
        $q = $this->db->prepare("SELECT c.*, r.nome AS restaurante_nome, r.slug AS restaurante_slug, CONCAT(u.nome, ' ', u.sobrenome) AS autor,
                (SELECT COUNT(*) FROM suporte_mensagens m WHERE m.id_chamado = c.id) AS mensagens
            FROM suporte_chamados c JOIN restaurantes r ON r.id = c.id_restaurante LEFT JOIN `user` u ON u.id = c.id_user
            WHERE 1 = 1" . $onde . " ORDER BY CASE WHEN c.status <> 'resolvido' AND c.ultima_de = 'restaurante' THEN 0 ELSE 1 END, c.ultima_mensagem_em DESC, c.id DESC
            LIMIT " . self::POR_PAGINA . ' OFFSET ' . (max(0, $pagina - 1) * self::POR_PAGINA));
        $q->execute($params);
        return ['itens' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn()];
    }

    /** @return array<string,int> */
    public function contagemPlataforma(): array
    {
        $r = $this->db->query("SELECT SUM(CASE WHEN status <> 'resolvido' AND ultima_de = 'restaurante' THEN 1 ELSE 0 END),
                SUM(CASE WHEN status <> 'resolvido' THEN 1 ELSE 0 END), SUM(CASE WHEN status = 'resolvido' THEN 1 ELSE 0 END), COUNT(*)
            FROM suporte_chamados")->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0];
        return ['aguardando' => (int) $r[0], 'abertos' => (int) $r[1], 'resolvidos' => (int) $r[2], 'todos' => (int) $r[3]];
    }

    /** Chamado com o restaurante e quem abriu. $idRestaurante null = plataforma (qualquer restaurante). @return array<string,mixed>|null */
    public function buscar(int $id, ?int $idRestaurante): ?array
    {
        $sql = "SELECT c.*, r.nome AS restaurante_nome, r.slug AS restaurante_slug, r.status_rest, CONCAT(u.nome, ' ', u.sobrenome) AS autor, u.email AS autor_email,
                u.ativo AS autor_ativo, u.excluido_em AS autor_excluido
            FROM suporte_chamados c JOIN restaurantes r ON r.id = c.id_restaurante LEFT JOIN `user` u ON u.id = c.id_user WHERE c.id = ?";
        $params = [$id];
        if ($idRestaurante !== null) {
            $sql .= ' AND c.id_restaurante = ?';
            $params[] = $idRestaurante;
        }
        $q = $this->db->prepare($sql);
        $q->execute($params);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        return $c === false ? null : $c;
    }

    /** @return list<array<string,mixed>> */
    public function mensagens(int $idChamado): array
    {
        $q = $this->db->prepare("SELECT m.id, m.origem, m.texto, m.criado_em, CONCAT(u.nome, ' ', u.sobrenome) AS autor, u.cargo
            FROM suporte_mensagens m LEFT JOIN `user` u ON u.id = m.id_user WHERE m.id_chamado = ? ORDER BY m.id");
        $q->execute([$idChamado]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function criar(int $idRestaurante, int $idUser, string $assunto, string $categoria, string $texto): int
    {
        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO suporte_chamados (id_restaurante, id_user, assunto, categoria, status, ultima_de, ultima_mensagem_em, visto_restaurante_em, criado_em, atualizado_em)
                VALUES (?, ?, ?, ?, 'aberto', 'restaurante', ?, ?, ?, ?)")->execute([$idRestaurante, $idUser, $assunto, $categoria, $agora, $agora, $agora, $agora]);
            $q = $this->db->prepare('SELECT MAX(id) FROM suporte_chamados WHERE id_restaurante = ? AND id_user = ?');
            $q->execute([$idRestaurante, $idUser]);
            $id = (int) $q->fetchColumn();
            $this->db->prepare("INSERT INTO suporte_mensagens (id_chamado, id_restaurante, id_user, origem, texto, criado_em) VALUES (?, ?, ?, 'restaurante', ?, ?)")
                ->execute([$id, $idRestaurante, $idUser, $texto, $agora]);
            $this->db->commit();
            return $id;
        } catch (\Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
    }

    /**
     * Nova mensagem. Restaurante respondendo reabre o chamado resolvido; suporte respondendo passa a "em atendimento".
     * @param array<string,mixed> $c chamado
     */
    public function responder(array $c, int $idUser, string $origem, string $texto): void
    {
        $agora = date('Y-m-d H:i:s');
        $status = $origem === 'restaurante'
            ? ($c['status'] === 'resolvido' ? 'aberto' : (string) $c['status'])
            : ($c['status'] === 'aberto' ? 'em_atendimento' : (string) $c['status']);
        $visto = $origem === 'restaurante' ? 'visto_restaurante_em' : 'visto_suporte_em';
        // O outro lado ainda nao viu esta mensagem (zerar evita empate quando tudo acontece no mesmo segundo).
        $outro = $origem === 'restaurante' ? 'visto_suporte_em' : 'visto_restaurante_em';
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO suporte_mensagens (id_chamado, id_restaurante, id_user, origem, texto, criado_em) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([(int) $c['id'], (int) $c['id_restaurante'], $idUser, $origem, $texto, $agora]);
            $this->db->prepare("UPDATE suporte_chamados SET status = ?, resolvido_em = CASE WHEN ? = 'resolvido' THEN resolvido_em ELSE NULL END,
                    ultima_de = ?, ultima_mensagem_em = ?, $visto = ?, $outro = NULL, atualizado_em = ? WHERE id = ?")
                ->execute([$status, $status, $origem, $agora, $agora, $agora, (int) $c['id']]);
            $this->db->commit();
        } catch (\Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
    }

    public function mudarStatus(int $id, string $status): void
    {
        $agora = date('Y-m-d H:i:s');
        $this->db->prepare('UPDATE suporte_chamados SET status = ?, resolvido_em = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$status, $status === 'resolvido' ? $agora : null, $agora, $id]);
    }

    public function marcarVisto(int $id, string $lado): void
    {
        $coluna = $lado === 'restaurante' ? 'visto_restaurante_em' : 'visto_suporte_em';
        $this->db->prepare("UPDATE suporte_chamados SET $coluna = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
    }

    /** Respostas do suporte que o restaurante ainda nao viu (contador do menu). */
    public function novidadesRestaurante(int $idRestaurante): int
    {
        $q = $this->db->prepare("SELECT COUNT(*) FROM suporte_chamados WHERE id_restaurante = ? AND ultima_de = 'suporte'
            AND (visto_restaurante_em IS NULL OR visto_restaurante_em < ultima_mensagem_em)");
        $q->execute([$idRestaurante]);
        return (int) $q->fetchColumn();
    }

    /** Chamados esperando resposta da plataforma (contador do menu). */
    public function aguardandoPlataforma(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM suporte_chamados WHERE status <> 'resolvido' AND ultima_de = 'restaurante'")->fetchColumn();
    }

    /** @return list<string> e-mails dos administradores ativos (avisos de chamado novo) */
    public function emailsAdministradores(): array
    {
        $q = $this->db->query("SELECT email FROM `user` WHERE cargo = 'adm_master' AND ativo = 1 AND excluido_em IS NULL");
        return array_values(array_filter(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !str_ends_with(strtolower($e), '.invalid')));
    }

    /** @return array{0:string, 1:list<mixed>} */
    private static function filtroSituacao(string $filtro): array
    {
        return match ($filtro) {
            'aguardando' => [" AND c.status <> 'resolvido' AND c.ultima_de = 'restaurante'", []],
            'abertos' => [" AND c.status <> 'resolvido'", []],
            'resolvidos' => [" AND c.status = 'resolvido'", []],
            default => ['', []],
        };
    }
}
