<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/** Assinaturas do restaurante no Cardapim (historico completo, uma linha por periodo contratado). */
final class AssinaturaRepository
{
    private const CAMPOS = 'a.id, a.plano_id, p.nome AS plano_nome, p.descricao AS plano_descricao, a.valor, a.periodo, a.status,
        a.inicio_em, a.vence_em, a.cancelada_em, a.encerrada_em, a.criado_em';

    public function __construct(private readonly PDO $db)
    {
    }

    /** Assinatura vigente (ativa ou cancelada ainda dentro do periodo pago). @return array<string,mixed>|null */
    public function vigente(int $idRestaurante): ?array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM assinaturas a JOIN planos p ON p.id = a.plano_id
            WHERE a.id_restaurante = ? AND a.status IN (\'ativa\', \'cancelada\') ORDER BY a.id DESC LIMIT 1');
        $query->execute([$idRestaurante]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function historico(int $idRestaurante, int $limite = 24): array
    {
        $query = $this->db->prepare('SELECT ' . self::CAMPOS . ' FROM assinaturas a JOIN planos p ON p.id = a.plano_id
            WHERE a.id_restaurante = ? ORDER BY a.id DESC LIMIT ' . max(1, min(100, $limite)));
        $query->execute([$idRestaurante]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Trava a linha vigente para alterar (evita dois cliques criando duas assinaturas). Chamar em transacao. */
    public function travarRestaurante(int $idRestaurante, string $sufixoTrava): void
    {
        $this->db->prepare('SELECT id FROM restaurantes WHERE id = ?' . $sufixoTrava)->execute([$idRestaurante]);
    }

    /** @param array{plano_id:int,valor:string,periodo:string,inicio_em:string,vence_em:string} $d */
    public function criar(int $idRestaurante, array $d): void
    {
        $this->db->prepare('INSERT INTO assinaturas (id_restaurante, plano_id, valor, periodo, status, inicio_em, vence_em)
            VALUES (?, ?, ?, ?, \'ativa\', ?, ?)')
            ->execute([$idRestaurante, $d['plano_id'], $d['valor'], $d['periodo'], $d['inicio_em'], $d['vence_em']]);
        $this->db->prepare('UPDATE restaurantes SET plano_id = ? WHERE id = ?')->execute([$d['plano_id'], $idRestaurante]);
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
}
