<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/**
 * Assinaturas do restaurante (historico completo, uma linha por periodo/plano contratado) e as faturas geradas
 * pelo Asaas da plataforma. Toda consulta filtra pelo restaurante.
 */
final class AssinaturaRepository
{
    private const CAMPOS = 'a.id, a.id_restaurante, a.plano_id, p.nome AS plano_nome, p.descricao AS plano_descricao, a.valor, a.periodo, a.status,
        a.inicio_em, a.vence_em, a.cancelada_em, a.encerrada_em, a.ambiente, a.asaas_cliente_id, a.asaas_assinatura_id, a.cobranca_erro, a.criado_em';

    public function __construct(private readonly PDO $db)
    {
    }

    /** Assinatura vigente (aguardando, ativa ou cancelada ainda dentro do periodo pago). @return array<string,mixed>|null */
    public function vigente(int $idRestaurante): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM assinaturas a JOIN planos p ON p.id = a.plano_id
            WHERE a.id_restaurante = ? AND a.status IN (\'aguardando\', \'ativa\', \'cancelada\') ORDER BY a.id DESC LIMIT 1');
        $query->execute([$idRestaurante]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM assinaturas a JOIN planos p ON p.id = a.plano_id WHERE a.id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Assinatura dona de uma assinatura do Asaas (a mais recente: trocar de plano reaproveita a do Asaas). @return array<string,mixed>|null */
    public function porAsaas(string $ambiente, string $asaasId): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM assinaturas a JOIN planos p ON p.id = a.plano_id
            WHERE a.ambiente = ? AND a.asaas_assinatura_id = ? ORDER BY a.id DESC LIMIT 1');
        $query->execute([$ambiente, $asaasId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Cliente ja cadastrado no Asaas para este restaurante (reaproveitado ao assinar de novo). */
    public function clienteAsaas(int $idRestaurante, string $ambiente): ?string
    {
        $query = $this->db->prepare('SELECT asaas_cliente_id FROM assinaturas WHERE id_restaurante = ? AND ambiente = ? AND asaas_cliente_id IS NOT NULL ORDER BY id DESC LIMIT 1');
        $query->execute([$idRestaurante, $ambiente]);
        $id = $query->fetchColumn();
        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return list<array<string,mixed>> */
    public function historico(int $idRestaurante, int $limite = 24): array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM assinaturas a JOIN planos p ON p.id = a.plano_id
            WHERE a.id_restaurante = ? ORDER BY a.id DESC LIMIT ' . max(1, min(100, $limite)));
        $query->execute([$idRestaurante]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Trava a linha do restaurante para alterar (evita dois cliques criando duas assinaturas). Chamar em transacao. */
    public function travarRestaurante(int $idRestaurante, string $sufixoTrava): void
    {
        $this->db->prepare('SELECT id FROM restaurantes WHERE id = ?' . $sufixoTrava)->execute([$idRestaurante]);
    }

    /**
     * @param array{plano_id:int,valor:string,periodo:string,inicio_em:string,vence_em:?string,status?:string,
     *   ambiente?:?string,asaas_cliente_id?:?string,asaas_assinatura_id?:?string} $d
     */
    public function criar(int $idRestaurante, array $d): int
    {
        $this->db->prepare('INSERT INTO assinaturas (id_restaurante, plano_id, valor, periodo, status, inicio_em, vence_em, ambiente, asaas_cliente_id, asaas_assinatura_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idRestaurante, $d['plano_id'], $d['valor'], $d['periodo'], $d['status'] ?? 'ativa', $d['inicio_em'], $d['vence_em'],
                $d['ambiente'] ?? null, $d['asaas_cliente_id'] ?? null, $d['asaas_assinatura_id'] ?? null]);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE restaurantes SET plano_id = ? WHERE id = ?')->execute([$d['plano_id'], $idRestaurante]);
        return $id;
    }

    public function mudarStatus(int $idRestaurante, int $id, string $status, string $agora): void
    {
        $sql = match ($status) {
            'ativa' => 'UPDATE assinaturas SET status = \'ativa\', cancelada_em = NULL WHERE id = ? AND id_restaurante = ?',
            'cancelada' => 'UPDATE assinaturas SET status = \'cancelada\', cancelada_em = ? WHERE id = ? AND id_restaurante = ?',
            'encerrada' => 'UPDATE assinaturas SET status = \'encerrada\', encerrada_em = ? WHERE id = ? AND id_restaurante = ?',
            default => throw new \InvalidArgumentException('Status invalido.'),
        };
        $this->db->prepare($sql)->execute($status === 'ativa' ? [$id, $idRestaurante] : [$agora, $id, $idRestaurante]);
    }

    /** Liga (ou desliga, com nulls) a assinatura do Cardapim a do Asaas. $erro = motivo de nao ter gerado a cobranca. */
    public function salvarAsaas(int $id, ?string $ambiente, ?string $cliente, ?string $assinatura, ?string $erro): void
    {
        $this->db->prepare('UPDATE assinaturas SET ambiente = ?, asaas_cliente_id = ?, asaas_assinatura_id = ?, cobranca_erro = ? WHERE id = ?')
            ->execute([$ambiente, $cliente, $assinatura, $erro !== null ? mb_substr($erro, 0, 255) : null, $id]);
    }

    /** Pagamento confirmado: assinatura ativa e paga ate $venceEm (nunca diminui). */
    public function marcarPaga(int $id, string $venceEm): void
    {
        $this->db->prepare('UPDATE assinaturas SET status = CASE WHEN status = \'aguardando\' THEN \'ativa\' ELSE status END,
            vence_em = CASE WHEN vence_em IS NULL OR vence_em < ? THEN ? ELSE vence_em END WHERE id = ?')
            ->execute([$venceEm, $venceEm, $id]);
    }

    /* ---------- Restaurante ---------- */

    public function statusRestaurante(int $idRestaurante): ?string
    {
        $query = $this->db->prepare('SELECT status_rest FROM restaurantes WHERE id = ?');
        $query->execute([$idRestaurante]);
        $s = $query->fetchColumn();
        return is_string($s) ? $s : null;
    }

    /** Muda o acesso do restaurante so a partir dos status esperados (suspenso/inativo pela administracao nunca mudam aqui). */
    public function mudarRestaurante(int $idRestaurante, string $novo, array $deStatus, bool $registrarPagamento = false): bool
    {
        $marcas = implode(',', array_fill(0, count($deStatus), '?'));
        $sql = 'UPDATE restaurantes SET status_rest = ?' . ($registrarPagamento ? ', ultimo_pagamento = ?' : '') . ' WHERE id = ? AND status_rest IN (' . $marcas . ')';
        $params = [$novo, ...($registrarPagamento ? [date('Y-m-d')] : []), $idRestaurante, ...$deStatus];
        $q = $this->db->prepare($sql);
        $q->execute($params);
        return $q->rowCount() > 0;
    }

    public function registrarPagamentoRestaurante(int $idRestaurante): void
    {
        $this->db->prepare('UPDATE restaurantes SET ultimo_pagamento = ? WHERE id = ?')->execute([date('Y-m-d'), $idRestaurante]);
    }

    /* ---------- Faturas ---------- */

    /**
     * Grava ou atualiza a fatura vinda do Asaas (mesmo id nunca duplica). @return array{id:int, status_antes:?string}
     * @param array{id:string,status:string,valor:string,vencimento:string,forma:string,link_fatura:?string,link_boleto:?string,pago_em:?string} $f
     */
    public function salvarFatura(int $idRestaurante, ?int $idAssinatura, string $ambiente, array $f, string $status): array
    {
        $q = $this->db->prepare('SELECT id, status FROM assinatura_faturas WHERE ambiente = ? AND asaas_id = ?');
        $q->execute([$ambiente, $f['id']]);
        $atual = $q->fetch(PDO::FETCH_ASSOC);
        $pagoEm = $f['pago_em'] !== null ? $f['pago_em'] . ' 00:00:00' : ($status === 'paga' ? date('Y-m-d H:i:s') : null);
        if ($atual === false) {
            $this->db->prepare('INSERT INTO assinatura_faturas (id_restaurante, id_assinatura, ambiente, asaas_id, valor, vencimento, status, forma, link_fatura, link_boleto, pago_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idRestaurante, $idAssinatura, $ambiente, $f['id'], $f['valor'], $f['vencimento'], $status, $f['forma'] ?: null, $f['link_fatura'], $f['link_boleto'], $pagoEm]);
            return ['id' => (int) $this->db->lastInsertId(), 'status_antes' => null];
        }
        // Fatura paga nao volta a ficar pendente por um aviso atrasado (o Asaas pode entregar fora de ordem).
        $novo = in_array($atual['status'], ['paga', 'estornada'], true) && in_array($status, ['pendente', 'vencida'], true) ? $atual['status'] : $status;
        $this->db->prepare('UPDATE assinatura_faturas SET id_assinatura = COALESCE(?, id_assinatura), valor = ?, vencimento = ?, status = ?, forma = COALESCE(?, forma),
            link_fatura = COALESCE(?, link_fatura), link_boleto = COALESCE(?, link_boleto), pago_em = COALESCE(pago_em, ?) WHERE id = ?')
            ->execute([$idAssinatura, $f['valor'], $f['vencimento'], $novo, $f['forma'] ?: null, $f['link_fatura'], $f['link_boleto'], $novo === 'paga' ? $pagoEm : null, $atual['id']]);
        return ['id' => (int) $atual['id'], 'status_antes' => (string) $atual['status']];
    }

    /** @return list<array<string,mixed>> */
    public function faturas(int $idRestaurante, int $limite = 24): array
    {
        $query = $this->db->prepare('SELECT id, valor, vencimento, status, forma, link_fatura, link_boleto, pago_em FROM assinatura_faturas
            WHERE id_restaurante = ? AND status <> \'cancelada\' ORDER BY vencimento DESC, id DESC LIMIT ' . max(1, min(100, $limite)));
        $query->execute([$idRestaurante]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Fatura em aberto mais antiga vencida antes de $limite (Y-m-d). @return array<string,mixed>|null */
    public function faturaAtrasada(int $idRestaurante, string $limite): ?array
    {
        $query = $this->db->prepare('SELECT id, valor, vencimento, link_fatura FROM assinatura_faturas
            WHERE id_restaurante = ? AND status IN (\'pendente\', \'vencida\') AND vencimento < ? ORDER BY vencimento ASC LIMIT 1');
        $query->execute([$idRestaurante, $limite]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Proxima fatura em aberto (para o botao "Pagar"). @return array<string,mixed>|null */
    public function faturaEmAberto(int $idRestaurante): ?array
    {
        $query = $this->db->prepare('SELECT id, valor, vencimento, status, link_fatura, link_boleto FROM assinatura_faturas
            WHERE id_restaurante = ? AND status IN (\'pendente\', \'vencida\') ORDER BY vencimento ASC LIMIT 1');
        $query->execute([$idRestaurante]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Remove da lista as faturas ainda em aberto de uma assinatura (o Asaas apagou ao cancelar/trocar). */
    public function cancelarFaturasAbertas(int $idRestaurante, string $ambiente, string $asaasAssinatura): void
    {
        $this->db->prepare('UPDATE assinatura_faturas SET status = \'cancelada\' WHERE id_restaurante = ? AND ambiente = ? AND status IN (\'pendente\', \'vencida\')
            AND id_assinatura IN (SELECT id FROM assinaturas WHERE id_restaurante = ? AND asaas_assinatura_id = ?)')
            ->execute([$idRestaurante, $ambiente, $idRestaurante, $asaasAssinatura]);
    }
}
