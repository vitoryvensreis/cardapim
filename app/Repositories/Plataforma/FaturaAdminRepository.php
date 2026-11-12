<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Plataforma;

use PDO;

/**
 * Faturas das assinaturas vistas pela plataforma (/painel/faturas). Somente leitura: quem grava e a
 * AssinaturaCobranca (aviso do Asaas ou "Atualizar agora").
 */
final class FaturaAdminRepository
{
    public const POR_PAGINA = 25;
    public const SITUACOES = ['pendente', 'paga', 'vencida', 'cancelada', 'estornada'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{situacao:?string, busca:string, mes:?string} $f mes no formato AAAA-MM (pelo vencimento)
     * @return array{itens:list<array<string,mixed>>, total:int}
     */
    public function listar(string $ambiente, array $f, int $pagina): array
    {
        [$onde, $args] = $this->filtros($ambiente, $f);
        $total = $this->db->prepare('SELECT COUNT(*) FROM assinatura_faturas fa JOIN restaurantes r ON r.id = fa.id_restaurante WHERE ' . $onde);
        $total->execute($args);
        $q = $this->db->prepare("SELECT fa.id, fa.id_restaurante, fa.valor, fa.vencimento, fa.status, fa.forma, fa.link_fatura, fa.pago_em, fa.ambiente,
                r.nome AS restaurante_nome, r.status_rest, p.nome AS plano_nome, a.periodo
            FROM assinatura_faturas fa
            JOIN restaurantes r ON r.id = fa.id_restaurante
            LEFT JOIN assinaturas a ON a.id = fa.id_assinatura
            LEFT JOIN planos p ON p.id = a.plano_id
            WHERE $onde
            ORDER BY FIELD(fa.status, 'vencida', 'pendente', 'paga', 'estornada', 'cancelada'), fa.vencimento DESC, fa.id DESC
            LIMIT " . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA));
        $q->execute($args);
        return ['itens' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn()];
    }

    /** @return array<string,int> quantidade por situacao (com os demais filtros, sem o de situacao) */
    public function contagem(string $ambiente, array $f): array
    {
        [$onde, $args] = $this->filtros($ambiente, [...$f, 'situacao' => null]);
        $q = $this->db->prepare('SELECT fa.status, COUNT(*) FROM assinatura_faturas fa JOIN restaurantes r ON r.id = fa.id_restaurante WHERE ' . $onde . ' GROUP BY fa.status');
        $q->execute($args);
        $n = array_fill_keys(self::SITUACOES, 0);
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$st, $qt]) {
            $n[$st] = (int) $qt;
        }
        return $n;
    }

    /**
     * Numeros do topo: recebido no mes (pela data do pagamento), a receber, vencido e restaurantes com atraso.
     * @return array{recebido_mes:string, recebidas_mes:int, a_receber:string, abertas:int, vencido:string, vencidas:int, inadimplentes:int}
     */
    public function indicadores(string $ambiente, string $inicioMes, string $fimMes): array
    {
        $q = $this->db->prepare("SELECT
                COALESCE(SUM(CASE WHEN status = 'paga' AND pago_em >= ? AND pago_em < ? THEN valor END), 0),
                COALESCE(SUM(CASE WHEN status = 'paga' AND pago_em >= ? AND pago_em < ? THEN 1 END), 0),
                COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor END), 0),
                COALESCE(SUM(CASE WHEN status = 'pendente' THEN 1 END), 0),
                COALESCE(SUM(CASE WHEN status = 'vencida' THEN valor END), 0),
                COALESCE(SUM(CASE WHEN status = 'vencida' THEN 1 END), 0),
                COUNT(DISTINCT CASE WHEN status = 'vencida' THEN id_restaurante END)
            FROM assinatura_faturas WHERE ambiente = ?");
        $q->execute([$inicioMes, $fimMes, $inicioMes, $fimMes, $ambiente]);
        $r = $q->fetch(PDO::FETCH_NUM) ?: [0, 0, 0, 0, 0, 0, 0];
        return [
            'recebido_mes' => (string) $r[0], 'recebidas_mes' => (int) $r[1],
            'a_receber' => (string) $r[2], 'abertas' => (int) $r[3],
            'vencido' => (string) $r[4], 'vencidas' => (int) $r[5],
            'inadimplentes' => (int) $r[6],
        ];
    }

    /** Ha faturas no outro ambiente? (mostra o atalho para ve-las) */
    public function temNoAmbiente(string $ambiente): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM assinatura_faturas WHERE ambiente = ? LIMIT 1');
        $q->execute([$ambiente]);
        return $q->fetchColumn() !== false;
    }

    /**
     * Assinaturas cobradas pelo Asaas para "Atualizar agora": primeiro as que tem fatura em aberto.
     * @return list<int>
     */
    public function assinaturasParaAtualizar(string $ambiente, int $limite): array
    {
        $q = $this->db->prepare("SELECT a.id FROM assinaturas a
            WHERE a.ambiente = ? AND a.asaas_assinatura_id IS NOT NULL AND a.status = 'ativa'
            ORDER BY EXISTS (SELECT 1 FROM assinatura_faturas fa WHERE fa.id_assinatura = a.id AND fa.status IN ('pendente', 'vencida')) DESC, a.id DESC
            LIMIT " . max(1, $limite));
        $q->execute([$ambiente]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function filtros(string $ambiente, array $f): array
    {
        $onde = 'fa.ambiente = ?';
        $args = [$ambiente];
        if (($f['situacao'] ?? null) !== null) {
            $onde .= ' AND fa.status = ?';
            $args[] = $f['situacao'];
        }
        if (($f['mes'] ?? null) !== null) {
            $inicio = $f['mes'] . '-01';
            $onde .= ' AND fa.vencimento >= ? AND fa.vencimento < ?';
            array_push($args, $inicio, date('Y-m-d', (int) strtotime($inicio . ' +1 month')));
        }
        $busca = trim((string) ($f['busca'] ?? ''));
        if ($busca !== '') {
            $termo = '%' . strtr($busca, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $onde .= " AND (r.nome LIKE ? ESCAPE '!'" . (ctype_digit($busca) ? ' OR fa.id = ?' : '') . ')';
            $args[] = $termo;
            if (ctype_digit($busca)) {
                $args[] = (int) $busca;
            }
        }
        return [$onde, $args];
    }
}
