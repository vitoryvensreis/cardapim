<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;

/**
 * Modo caixa > Comandas: todas as comandas (mesa e balcao) do dia, da semana ou do mes, abertas, fechadas ou
 * canceladas, com quem abriu, quem fechou e os horarios. O detalhe mostra itens, pagamentos e o historico.
 * So leitura e sempre filtrado pelo restaurante.
 */
final class HistoricoComandas
{
    public const PERIODOS = ['hoje' => 'Hoje', 'semana' => 'Esta semana', 'mes' => 'Este mês'];
    public const STATUS = ['todas' => 'Todas', 'abertas' => 'Abertas', 'fechadas' => 'Fechadas', 'canceladas' => 'Canceladas'];
    public const LIMITE = 300;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /** Inicio do periodo (hoje 00:00, segunda-feira desta semana ou dia 1 deste mes). */
    public static function inicio(string $periodo, ?DateTimeImmutable $agora = null): DateTimeImmutable
    {
        $hoje = ($agora ?? new DateTimeImmutable('now'))->setTime(0, 0);
        return match ($periodo) {
            'semana' => $hoje->modify('-' . (((int) $hoje->format('N')) - 1) . ' days'),
            'mes' => $hoje->modify('first day of this month'),
            default => $hoje,
        };
    }

    /**
     * @return array{comandas:list<array<string,mixed>>, resumo:array{abertas:int,fechadas:int,canceladas:int,vendido:int}, limitado:bool}
     */
    public function listar(string $periodo, string $status): array
    {
        $periodo = isset(self::PERIODOS[$periodo]) ? $periodo : 'hoje';
        $status = isset(self::STATUS[$status]) ? $status : 'todas';
        $filtro = match ($status) {
            'abertas' => " AND p.status = 'aberto'",
            'fechadas' => " AND p.status = 'concluido'",
            'canceladas' => " AND p.status = 'cancelado'",
            default => '',
        };
        $nd = \Cardapim\Services\Pedidos\NumeroDoDia::sql($this->db, 'p');
        $q = $this->db->prepare("SELECT p.id, $nd AS numero, p.tipo, p.status, p.total, p.criado_em, p.concluido_em, p.cancelado_em,
                p.comanda_nome, p.cliente_nome, m.numero AS mesa_numero, m.apelido AS mesa_apelido, ua.nome AS aberta_por,
                (SELECT uf.nome FROM pedido_eventos e JOIN `user` uf ON uf.id = e.id_user
                 WHERE e.id_pedido = p.id AND e.id_restaurante = p.id_restaurante AND e.status IN ('concluido', 'cancelado')
                 ORDER BY e.id DESC LIMIT 1) AS fechada_por,
                (SELECT COUNT(*) FROM pedido_itens i WHERE i.id_pedido = p.id AND i.id_restaurante = p.id_restaurante AND i.cancelado_em IS NULL) AS itens
            FROM pedidos p
            LEFT JOIN mesas m ON m.id = p.id_mesa AND m.id_restaurante = p.id_restaurante
            LEFT JOIN `user` ua ON ua.id = p.id_user
            WHERE p.id_restaurante = ? AND p.tipo IN ('mesa', 'balcao') AND p.criado_em >= ?$filtro
            ORDER BY p.id DESC LIMIT " . (self::LIMITE + 1));
        $q->execute([$this->idRestaurante, self::inicio($periodo)->format('Y-m-d H:i:s')]);
        $linhas = $q->fetchAll(PDO::FETCH_ASSOC);
        $limitado = count($linhas) > self::LIMITE;
        $linhas = array_slice($linhas, 0, self::LIMITE);

        // Resumo do periodo inteiro (independe do filtro de situacao).
        $r = $this->db->prepare("SELECT SUM(status = 'aberto') AS abertas, SUM(status = 'concluido') AS fechadas, SUM(status = 'cancelado') AS canceladas,
                COALESCE(SUM(CASE WHEN status = 'concluido' THEN total ELSE 0 END), 0) AS vendido
            FROM pedidos WHERE id_restaurante = ? AND tipo IN ('mesa', 'balcao') AND criado_em >= ?");
        $r->execute([$this->idRestaurante, self::inicio($periodo)->format('Y-m-d H:i:s')]);
        $s = $r->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'comandas' => array_map(static fn (array $p): array => $p + ['rotulo' => self::rotulo($p)], $linhas),
            'resumo' => [
                'abertas' => (int) ($s['abertas'] ?? 0), 'fechadas' => (int) ($s['fechadas'] ?? 0),
                'canceladas' => (int) ($s['canceladas'] ?? 0), 'vendido' => Numero::centavos((string) ($s['vendido'] ?? '0')),
            ],
            'limitado' => $limitado,
        ];
    }

    /**
     * Detalhe de uma comanda (aberta, fechada ou cancelada): itens, pagamentos e historico com quem fez.
     * @return array<string,mixed>|null
     */
    public function detalhe(int $id, UsuarioLogado $usuario): ?array
    {
        $c = (new ComandaService($this->db, $this->idRestaurante, $usuario))->detalhes($id);
        if ($c === null) {
            return null;
        }
        $q = $this->db->prepare('SELECT e.status, e.descricao, e.criado_em, u.nome AS autor FROM pedido_eventos e
            LEFT JOIN `user` u ON u.id = e.id_user WHERE e.id_pedido = ? AND e.id_restaurante = ? ORDER BY e.id');
        $q->execute([$id, $this->idRestaurante]);
        $eventos = $q->fetchAll(PDO::FETCH_ASSOC);
        $u = $this->db->prepare('SELECT nome FROM `user` WHERE id = ?');
        $u->execute([(int) ($c['pedido']['id_user'] ?? 0)]);
        $abertaPor = $u->fetchColumn();
        $fechamento = null;
        foreach (array_reverse($eventos) as $ev) {
            if (in_array($ev['status'], ['concluido', 'cancelado'], true)) {
                $fechamento = $ev;
                break;
            }
        }
        $cancelador = $this->db->prepare('SELECT i.id, u.nome FROM pedido_itens i JOIN `user` u ON u.id = i.id_user_cancelou
            WHERE i.id_pedido = ? AND i.id_restaurante = ? AND i.cancelado_em IS NOT NULL');
        $cancelador->execute([$id, $this->idRestaurante]);
        return $c + [
            'eventos' => $eventos,
            'aberta_por' => $abertaPor !== false ? (string) $abertaPor : null,
            'fechamento' => $fechamento,
            'cancelados_por' => array_map('strval', $cancelador->fetchAll(PDO::FETCH_KEY_PAIR)),
        ];
    }

    /** @param array<string,mixed> $p */
    private static function rotulo(array $p): string
    {
        if ($p['tipo'] !== 'mesa') {
            return 'Balcão' . (trim((string) ($p['cliente_nome'] ?? '')) !== '' ? ' · ' . trim((string) $p['cliente_nome']) : '');
        }
        $mesa = MesaService::rotulo(['numero' => $p['mesa_numero'], 'apelido' => $p['mesa_apelido']]);
        return $mesa . (trim((string) ($p['comanda_nome'] ?? '')) !== '' ? ' · ' . trim((string) $p['comanda_nome']) : '');
    }
}
