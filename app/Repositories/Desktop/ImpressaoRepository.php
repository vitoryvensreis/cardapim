<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Desktop;

use PDO;

/**
 * Fila de impressao do Agent. Toda consulta filtra por restaurante; o dispositivo vem do token autenticado,
 * nunca do corpo da requisicao. Horarios pelo relogio do MySQL (NOW()), o mesmo de desktop_agent_devices.
 */
final class ImpressaoRepository
{
    /** Pedido de impressao nao reservado por um Agent nesse tempo expira (nao imprime atrasado). */
    public const EXPIRA_SEGUNDOS = 120;
    /** Agent que sabe imprimir consulta a cada 2,5 s; sem consulta ha 30 s, consideramos desligado. */
    public const AGENTE_SEGUNDOS = 30;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<array<string,mixed>> $blocos
     * @param int|null $idImpressora impressora escolhida (null = qualquer Agent, na impressora principal dele)
     */
    public function criar(int $idRestaurante, ?int $idPedido, string $tipo, string $titulo, array $blocos, ?int $ateItem, ?int $idUser, ?int $idImpressora = null, ?string $lote = null): int
    {
        $colunas = 'id_restaurante, id_pedido, tipo, titulo, conteudo, ate_item, id_user' . ($idImpressora !== null ? ', id_impressora' : '') . ($lote !== null ? ', lote' : '');
        $valores = [
            $idRestaurante, $idPedido, $tipo, mb_substr($titulo, 0, 80),
            json_encode($blocos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ateItem, $idUser,
        ];
        if ($idImpressora !== null) {
            $valores[] = $idImpressora;
        }
        if ($lote !== null) {
            $valores[] = $lote;
        }
        $this->db->prepare('INSERT INTO impressoes (' . $colunas . ', criado_em) VALUES ('
            . implode(', ', array_fill(0, count($valores), '?')) . ', NOW())')->execute($valores);
        return (int) $this->db->lastInsertId();
    }

    /** Situacao de um pedido de impressao do restaurante (para o aviso no navegador). @return array<string,mixed>|null */
    public function situacao(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT id, tipo, titulo, entregue_em, resultado, erro,
                criado_em < NOW() - INTERVAL ' . self::EXPIRA_SEGUNDOS . ' SECOND AS vencido
            FROM impressoes WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * Cupons de uma impressao dividida por estacao (mesmo lote), com o apelido da impressora.
     * @return list<array<string,mixed>>
     */
    public function lote(int $idRestaurante, string $lote): array
    {
        $q = $this->db->prepare('SELECT im.entregue_em, im.resultado, im.erro, COALESCE(i.apelido, \'\') AS apelido,
                im.criado_em < NOW() - INTERVAL ' . self::EXPIRA_SEGUNDOS . ' SECOND AS vencido
            FROM impressoes im LEFT JOIN impressoras i ON i.id = im.id_impressora
            WHERE im.id_restaurante = ? AND im.lote = ? ORDER BY im.id');
        $q->execute([$idRestaurante, $lote]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lote do cupom (null = cupom avulso ou migracao ainda nao aplicada). */
    public function loteDe(int $idRestaurante, int $id): ?string
    {
        $q = $this->db->prepare('SELECT lote FROM impressoes WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $lote = $q->fetchColumn();
        return is_string($lote) && $lote !== '' ? $lote : null;
    }

    /** O pedido do delivery ja teve as vias de producao enviadas? (nao repete ao aceitar de novo) */
    public function temProducao(int $idRestaurante, int $idPedido): bool
    {
        $q = $this->db->prepare("SELECT 1 FROM impressoes WHERE id_restaurante = ? AND id_pedido = ? AND tipo = 'producao' LIMIT 1");
        $q->execute([$idRestaurante, $idPedido]);
        return $q->fetchColumn() !== false;
    }

    /** Maior item da comanda ja impresso (cupom entregue a um Agent e sem erro). 0 = nada impresso ainda. */
    public function ultimoItemImpresso(int $idRestaurante, int $idPedido): int
    {
        $q = $this->db->prepare("SELECT COALESCE(MAX(ate_item), 0) FROM impressoes
            WHERE id_restaurante = ? AND id_pedido = ? AND entregue_em IS NOT NULL AND (resultado IS NULL OR resultado = 'ok')");
        $q->execute([$idRestaurante, $idPedido]);
        return (int) $q->fetchColumn();
    }

    /** Ha Agent que sabe imprimir ligado neste restaurante agora? */
    public function agenteImprimindo(int $idRestaurante): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM desktop_agent_devices
            WHERE id_restaurante = ? AND revogado_em IS NULL AND expira_em > NOW()
              AND impressao_em >= NOW() - INTERVAL ' . self::AGENTE_SEGUNDOS . ' SECOND LIMIT 1');
        $q->execute([$idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    public function marcarAgente(int $idDispositivo): void
    {
        $this->db->prepare('UPDATE desktop_agent_devices SET impressao_em = NOW() WHERE id = ?')->execute([$idDispositivo]);
    }

    /**
     * Reserva para este Agent os pedidos de impressao recentes ainda sem dono. O UPDATE condicionado garante
     * que dois computadores do mesmo restaurante nunca imprimam o mesmo cupom.
     * - $porImpressora (Agent 0.8.0+, com lista de impressoras): pega os cupons sem impressora escolhida e os
     *   das impressoras dele; cada cupom volta com a chave da impressora ("" = a principal).
     * - Agent 0.7.0: so os cupons sem impressora escolhida.
     * - $temColuna: a migracao das impressoras ja foi aplicada.
     * @return list<array{id:int,titulo:string,impressora:string,blocos:list<array<string,mixed>>}>
     */
    public function reservar(int $idRestaurante, int $idDispositivo, int $limite = 10, bool $porImpressora = false, bool $temColuna = false): array
    {
        $params = [$idRestaurante];
        if (!$temColuna) {
            $sql = "SELECT im.id, im.titulo, im.conteudo, '' AS chave FROM impressoes im WHERE im.id_restaurante = ?";
        } elseif ($porImpressora) {
            $sql = "SELECT im.id, im.titulo, im.conteudo, COALESCE(i.chave, '') AS chave FROM impressoes im
                LEFT JOIN impressoras i ON i.id = im.id_impressora
                WHERE im.id_restaurante = ? AND (im.id_impressora IS NULL OR (i.id_dispositivo = ? AND i.ativo = 1))";
            $params[] = $idDispositivo;
        } else {
            $sql = "SELECT im.id, im.titulo, im.conteudo, '' AS chave FROM impressoes im WHERE im.id_restaurante = ? AND im.id_impressora IS NULL";
        }
        $q = $this->db->prepare($sql . ' AND im.entregue_em IS NULL AND im.criado_em >= NOW() - INTERVAL ' . self::EXPIRA_SEGUNDOS . ' SECOND
            ORDER BY im.id LIMIT ' . max(1, min(20, $limite)));
        $q->execute($params);
        $pegar = $this->db->prepare('UPDATE impressoes SET id_dispositivo = ?, entregue_em = NOW()
            WHERE id = ? AND id_restaurante = ? AND entregue_em IS NULL');
        $reservados = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $pegar->execute([$idDispositivo, (int) $linha['id'], $idRestaurante]);
            if ($pegar->rowCount() !== 1) {
                continue; // outro computador pegou antes
            }
            $blocos = json_decode((string) $linha['conteudo'], true);
            $reservados[] = [
                'id' => (int) $linha['id'],
                'titulo' => (string) $linha['titulo'],
                'impressora' => (string) $linha['chave'],
                'blocos' => is_array($blocos) ? array_values($blocos) : [],
            ];
        }
        return $reservados;
    }

    /** O Agent desta impressora esta ligado agora? */
    public function impressoraLigada(int $idRestaurante, int $idImpressora): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM impressoras i JOIN desktop_agent_devices d ON d.id = i.id_dispositivo
            WHERE i.id = ? AND i.id_restaurante = ? AND i.ativo = 1 AND d.revogado_em IS NULL AND d.expira_em > NOW()
              AND d.impressao_em >= NOW() - INTERVAL ' . self::AGENTE_SEGUNDOS . ' SECOND LIMIT 1');
        $q->execute([$idImpressora, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    /** O Agent so informa o resultado do que ele mesmo reservou. */
    public function registrarResultado(int $idRestaurante, int $idDispositivo, int $id, bool $ok, string $erro): bool
    {
        $q = $this->db->prepare('UPDATE impressoes SET resultado = ?, erro = ?, resultado_em = NOW()
            WHERE id = ? AND id_restaurante = ? AND id_dispositivo = ? AND resultado IS NULL');
        $q->execute([$ok ? 'ok' : 'erro', $ok ? null : mb_substr($erro, 0, 200), $id, $idRestaurante, $idDispositivo]);
        return $q->rowCount() === 1;
    }
}
