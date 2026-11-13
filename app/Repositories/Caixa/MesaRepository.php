<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Caixa;

use PDO;

/** Mesas do salao e as comandas abertas de cada uma. */
final class MesaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Mesas (nao excluidas) com as comandas abertas de cada uma (uma mesa pode ter varias, uma por pessoa).
     * Resumo por mesa: comanda_id (a mais antiga, aberta ao tocar na mesa), comandas (quantas), comanda_total (soma),
     * comanda_itens (soma), comanda_desde (a mais antiga) e lista_comandas (id, numero, comanda_nome, total, itens).
     * @return list<array<string,mixed>>
     */
    public function listar(int $idRestaurante): array
    {
        $q = $this->db->prepare(
            "SELECT m.id, m.numero, m.apelido, m.lugares, m.ativo,
                    (SELECT COUNT(*) FROM pedidos h WHERE h.id_mesa = m.id AND h.id_restaurante = m.id_restaurante) AS historico
             FROM mesas m
             WHERE m.id_restaurante = ? AND m.excluida_em IS NULL
             ORDER BY m.numero"
        );
        $q->execute([$idRestaurante]);
        $mesas = $q->fetchAll(PDO::FETCH_ASSOC);
        $porMesa = [];
        foreach ($this->comandasAbertas($idRestaurante) as $c) {
            $porMesa[(int) $c['id_mesa']][] = $c;
        }
        foreach ($mesas as &$m) {
            $lista = $porMesa[(int) $m['id']] ?? [];
            $centavos = 0;
            $itens = 0;
            foreach ($lista as $c) {
                $centavos += \Cardapim\Shared\Numero::centavos((string) $c['total']);
                $itens += (int) $c['itens'];
            }
            $m['comanda_id'] = $lista !== [] ? (int) $lista[0]['id'] : null;
            $m['comandas'] = count($lista);
            $m['comanda_total'] = sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
            $m['comanda_itens'] = $itens;
            $m['comanda_desde'] = $lista !== [] ? (string) $lista[0]['criado_em'] : null;
            $m['lista_comandas'] = $lista;
        }
        unset($m);
        return $mesas;
    }

    /**
     * Comandas abertas das mesas do restaurante (todas, ou so de uma mesa), da mais antiga para a mais nova.
     * @return list<array<string,mixed>>
     */
    public function comandasAbertas(int $idRestaurante, ?int $idMesa = null): array
    {
        $q = $this->db->prepare(
            "SELECT p.id, p.id_mesa, p.numero, p.comanda_nome, p.total, p.criado_em,
                    (SELECT COALESCE(SUM(i.quantidade), 0) FROM pedido_itens i WHERE i.id_pedido = p.id AND i.id_restaurante = p.id_restaurante AND i.cancelado_em IS NULL) AS itens
             FROM pedidos p
             WHERE p.id_restaurante = ? AND p.status = 'aberto' AND p.id_mesa IS NOT NULL" . ($idMesa !== null ? ' AND p.id_mesa = ?' : '') . "
             ORDER BY p.id"
        );
        $q->execute($idMesa !== null ? [$idRestaurante, $idMesa] : [$idRestaurante]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM mesas WHERE id = ? AND id_restaurante = ? AND excluida_em IS NULL');
        $q->execute([$id, $idRestaurante]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
        return $m === false ? null : $m;
    }

    /** Inclusive mesas excluidas (para mostrar o nome em pedidos antigos). @return array<string,mixed>|null */
    public function buscarQualquer(int $idRestaurante, int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM mesas WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $idRestaurante]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
        return $m === false ? null : $m;
    }

    public function numeroEmUso(int $idRestaurante, int $numero, ?int $exceto = null): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM mesas WHERE id_restaurante = ? AND numero = ? AND id <> ? LIMIT 1');
        $q->execute([$idRestaurante, $numero, $exceto ?? 0]);
        return $q->fetchColumn() !== false;
    }

    public function criar(int $idRestaurante, int $numero, ?string $apelido, ?int $lugares): int
    {
        $agora = date('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO mesas (id_restaurante, numero, apelido, lugares, ativo, criado_em, atualizado_em) VALUES (?, ?, ?, ?, 1, ?, ?)')
            ->execute([$idRestaurante, $numero, $apelido, $lugares, $agora, $agora]);
        $q = $this->db->prepare('SELECT id FROM mesas WHERE id_restaurante = ? AND numero = ?');
        $q->execute([$idRestaurante, $numero]);
        return (int) $q->fetchColumn();
    }

    /** Mesas 1 a 5 de um restaurante novo (chamar no cadastro do restaurante). A migration fez isso para os que ja existiam. */
    public function criarPadrao(int $idRestaurante): void
    {
        $q = $this->db->prepare('SELECT 1 FROM mesas WHERE id_restaurante = ? LIMIT 1');
        $q->execute([$idRestaurante]);
        if ($q->fetchColumn() !== false) {
            return;
        }
        for ($n = 1; $n <= 5; $n++) {
            $this->criar($idRestaurante, $n, null, 4);
        }
    }

    public function atualizar(int $idRestaurante, int $id, int $numero, ?string $apelido, ?int $lugares, bool $ativo): void
    {
        $this->db->prepare('UPDATE mesas SET numero = ?, apelido = ?, lugares = ?, ativo = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ? AND excluida_em IS NULL')
            ->execute([$numero, $apelido, $lugares, $ativo ? 1 : 0, date('Y-m-d H:i:s'), $id, $idRestaurante]);
    }

    public function temComandaAberta(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare("SELECT 1 FROM pedidos WHERE id_mesa = ? AND id_restaurante = ? AND status = 'aberto' LIMIT 1");
        $q->execute([$id, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    public function temHistorico(int $idRestaurante, int $id): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM pedidos WHERE id_mesa = ? AND id_restaurante = ? LIMIT 1');
        $q->execute([$id, $idRestaurante]);
        return $q->fetchColumn() !== false;
    }

    /** Mesa com historico: some da lista e libera o numero, mas os pedidos antigos continuam ligados a ela. */
    public function arquivar(int $idRestaurante, int $id): void
    {
        $this->db->prepare("UPDATE mesas SET apelido = COALESCE(apelido, CONCAT('Mesa ', numero)), numero = NULL, ativo = 0, excluida_em = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id, $idRestaurante]);
    }

    public function excluir(int $idRestaurante, int $id): void
    {
        $this->db->prepare('DELETE FROM mesas WHERE id = ? AND id_restaurante = ?')->execute([$id, $idRestaurante]);
    }
}
