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

    /** @param list<array<string,mixed>> $blocos */
    public function criar(int $idRestaurante, ?int $idPedido, string $tipo, string $titulo, array $blocos, ?int $ateItem, ?int $idUser): int
    {
        $this->db->prepare('INSERT INTO impressoes (id_restaurante, id_pedido, tipo, titulo, conteudo, ate_item, id_user, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([
                $idRestaurante, $idPedido, $tipo, mb_substr($titulo, 0, 80),
                json_encode($blocos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ateItem, $idUser,
            ]);
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
     * @return list<array{id:int,titulo:string,blocos:list<array<string,mixed>>}>
     */
    public function reservar(int $idRestaurante, int $idDispositivo, int $limite = 10): array
    {
        $q = $this->db->prepare('SELECT id, titulo, conteudo FROM impressoes
            WHERE id_restaurante = ? AND entregue_em IS NULL AND criado_em >= NOW() - INTERVAL ' . self::EXPIRA_SEGUNDOS . ' SECOND
            ORDER BY id LIMIT ' . max(1, min(20, $limite)));
        $q->execute([$idRestaurante]);
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
                'blocos' => is_array($blocos) ? array_values($blocos) : [],
            ];
        }
        return $reservados;
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
