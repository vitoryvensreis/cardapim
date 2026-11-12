<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use PDO;

/** Restaurantes vistos pela administracao da plataforma (todos os restaurantes). */
final class RestauranteAdminRepository
{
    public const STATUS = ['ativo', 'pendente', 'inadimplente', 'suspenso', 'inativo'];
    public const POR_PAGINA = 25;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{itens:list<array<string,mixed>>, total:int}
     */
    public function listar(string $busca, ?string $status, ?int $planoId, int $pagina): array
    {
        [$onde, $params] = $this->filtros($busca, $status, $planoId);
        $total = $this->db->prepare('SELECT COUNT(*) FROM restaurantes r WHERE ' . $onde);
        $total->execute($params);
        $q = $this->db->prepare(
            "SELECT r.id, r.nome, r.slug, r.cnpj, r.telefone, r.email, r.status_rest, r.cidade, r.estado, r.logo_path, r.created_at,
                    p.nome AS plano_nome, p.valor AS plano_valor, p.periodo AS plano_periodo,
                    (SELECT CONCAT(u.nome, ' ', u.sobrenome) FROM `user` u WHERE u.id_restaurante = r.id AND u.cargo = 'gerente' AND u.excluido_em IS NULL ORDER BY u.id LIMIT 1) AS gerente,
                    (SELECT COUNT(*) FROM `user` u WHERE u.id_restaurante = r.id AND u.excluido_em IS NULL) AS contas,
                    (SELECT MAX(pd.criado_em) FROM pedidos pd WHERE pd.id_restaurante = r.id) AS ultimo_pedido
             FROM restaurantes r LEFT JOIN planos p ON p.id = r.plano_id
             WHERE " . $onde . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . self::POR_PAGINA . ' OFFSET ' . (max(0, $pagina - 1) * self::POR_PAGINA)
        );
        $q->execute($params);
        return ['itens' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn()];
    }

    /** @return array<string,int> status => quantidade */
    public function contagemPorStatus(): array
    {
        $r = array_fill_keys(self::STATUS, 0);
        foreach ($this->db->query('SELECT status_rest, COUNT(*) AS n FROM restaurantes GROUP BY status_rest')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $r[(string) $l['status_rest']] = (int) $l['n'];
        }
        return $r;
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare(
            'SELECT r.*, p.nome AS plano_nome, p.valor AS plano_valor, p.periodo AS plano_periodo, p.visibilidade AS plano_visibilidade
             FROM restaurantes r LEFT JOIN planos p ON p.id = r.plano_id WHERE r.id = ?'
        );
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Contas do restaurante (sem as excluidas). @return list<array<string,mixed>> */
    public function contas(int $idRestaurante): array
    {
        $q = $this->db->prepare(
            "SELECT id, cargo, usuario, email, nome, sobrenome, ativo, ultimo_acesso, dois_fatores FROM `user`
             WHERE id_restaurante = ? AND excluido_em IS NULL
             ORDER BY CASE cargo WHEN 'gerente' THEN 0 WHEN 'caixa' THEN 1 WHEN 'garcom' THEN 2 ELSE 3 END, nome, sobrenome"
        );
        $q->execute([$idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Numeros do restaurante: pedidos (total, 30 dias, ultimo), caixas, contas financeiras, faturas pagas e integracoes conectadas.
     * @return array{pedidos:int, pedidos_30:int, vendas_30:string, ultimo_pedido:?string, caixas:int, contas_financeiras:int, faturas_pagas:int, integracoes:list<string>, produtos:int}
     */
    public function movimento(int $idRestaurante): array
    {
        $um = function (string $sql, array $p = []) use ($idRestaurante): mixed {
            $q = $this->db->prepare($sql);
            $q->execute([$idRestaurante, ...$p]);
            return $q->fetchColumn();
        };
        $desde = date('Y-m-d H:i:s', strtotime('-30 days'));
        $integracoes = $this->db->prepare("SELECT provedor FROM integracoes WHERE id_restaurante = ? AND status IN ('conectado', 'pendente') ORDER BY provedor");
        $integracoes->execute([$idRestaurante]);
        return [
            'pedidos' => (int) $um('SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ?'),
            'pedidos_30' => (int) $um("SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND status = 'concluido' AND concluido_em >= ?", [$desde]),
            'vendas_30' => (string) ($um("SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE id_restaurante = ? AND status = 'concluido' AND concluido_em >= ?", [$desde]) ?: '0'),
            'ultimo_pedido' => ($u = $um('SELECT MAX(criado_em) FROM pedidos WHERE id_restaurante = ?')) ? (string) $u : null,
            'caixas' => (int) $um('SELECT COUNT(*) FROM caixas WHERE id_restaurante = ?'),
            'contas_financeiras' => (int) $um('SELECT COUNT(*) FROM contas_financeiras WHERE id_restaurante = ?'),
            'faturas_pagas' => (int) $um("SELECT COUNT(*) FROM assinatura_faturas WHERE id_restaurante = ? AND status = 'paga'"),
            'integracoes' => array_map('strval', $integracoes->fetchAll(PDO::FETCH_COLUMN)),
            'produtos' => (int) $um('SELECT COUNT(*) FROM produtos WHERE id_restaurante = ?'),
        ];
    }

    public function cnpjEmUso(string $cnpj, int $ignorarId): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM restaurantes WHERE cnpj = ? AND id <> ? LIMIT 1');
        $q->execute([$cnpj, $ignorarId]);
        return $q->fetchColumn() !== false;
    }

    public function definirCnpj(int $id, ?string $cnpj): void
    {
        $this->db->prepare('UPDATE restaurantes SET cnpj = ? WHERE id = ?')->execute([$cnpj, $id]);
    }

    public function definirStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE restaurantes SET status_rest = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** Contas de equipe do restaurante (para desconectar ao suspender/excluir). @return list<int> */
    public function idsContas(int $idRestaurante): array
    {
        $q = $this->db->prepare('SELECT id FROM `user` WHERE id_restaurante = ?');
        $q->execute([$idRestaurante]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Apaga um restaurante SEM movimento (o servico confere antes) e tudo que e so dele. Os logs ficam (sem o vinculo com as
     * contas apagadas). Deve rodar dentro de transacao.
     */
    public function excluir(int $id): void
    {
        $contas = $this->idsContas($id);
        if ($contas !== []) {
            $marcas = implode(',', array_fill(0, count($contas), '?'));
            $this->db->prepare("UPDATE log_user SET id_user = NULL WHERE id_user IN ($marcas)")->execute($contas);
            $this->db->prepare("UPDATE contas_financeiras SET id_user = NULL WHERE id_user IN ($marcas)")->execute($contas);
            foreach (['user_sessoes_lembradas', 'user_codigos_recuperacao', 'entregador_posicoes'] as $tabela) {
                $this->db->prepare("DELETE FROM $tabela WHERE id_user IN ($marcas)")->execute($contas);
            }
        }
        $tabelas = [
            'produto_insumos', 'produtos', 'categorias', 'insumos', 'mesas', 'restaurante_horarios', 'restaurante_areas_entrega',
            'restaurante_entrega_pagamento', 'restaurante_avisos_whatsapp', 'integracao_eventos', 'integracoes', 'outbox_eventos',
            'asaas_clientes', 'asaas_clientes_salao', 'assinatura_faturas', 'assinaturas', 'contas_financeiras', 'entregador_posicoes',
        ];
        foreach ($tabelas as $tabela) {
            $this->db->prepare("DELETE FROM $tabela WHERE id_restaurante = ?")->execute([$id]);
        }
        $this->db->prepare('DELETE FROM `user` WHERE id_restaurante = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM restaurantes WHERE id = ?')->execute([$id]);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filtros(string $busca, ?string $status, ?int $planoId): array
    {
        $onde = ['1 = 1'];
        $params = [];
        if ($busca !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($busca)) . '%';
            $digitos = preg_replace('/\D+/', '', $busca) ?? '';
            $onde[] = "(LOWER(r.nome) LIKE ? ESCAPE '!' OR LOWER(r.slug) LIKE ? ESCAPE '!' OR LOWER(COALESCE(r.email, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(r.cidade, '')) LIKE ? ESCAPE '!'"
                . ($digitos !== '' ? ' OR r.cnpj LIKE ? OR r.telefone LIKE ?' : '') . ')';
            array_push($params, $like, $like, $like, $like);
            if ($digitos !== '') {
                array_push($params, '%' . $digitos . '%', '%' . $digitos . '%');
            }
        }
        if ($status !== null) {
            $onde[] = 'r.status_rest = ?';
            $params[] = $status;
        }
        if ($planoId !== null) {
            $onde[] = 'r.plano_id = ?';
            $params[] = $planoId;
        }
        return [implode(' AND ', $onde), $params];
    }
}
