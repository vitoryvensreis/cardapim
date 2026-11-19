<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Desktop;

use PDO;

/** A API nunca aceita IDs de restaurante/dispositivo do cliente. */
final class PesagemRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function registrar(int $restauranteId, int $dispositivoId, ?string $kg, bool $estavel): void
    {
        $q = $this->db->prepare(
            'INSERT INTO desktop_agent_pesagens
                (id_dispositivo, id_restaurante, peso_kg, estavel, atualizado_em)
             VALUES (?, ?, ?, ?, NOW(6))
             ON DUPLICATE KEY UPDATE
                peso_kg = VALUES(peso_kg),
                estavel = VALUES(estavel),
                atualizado_em = NOW(6)'
        );
        $q->execute([$dispositivoId, $restauranteId, $kg, $estavel ? 1 : 0]);
    }

    /** @return list<array<string, mixed>> */
    public function listar(int $restauranteId): array
    {
        $q = $this->db->prepare(
            'SELECT d.id, d.nome,
                    CASE WHEN p.atualizado_em >= NOW(6) - INTERVAL 3 SECOND
                        AND p.estavel = 1 THEN p.peso_kg ELSE NULL END AS peso_kg,
                    CASE WHEN p.atualizado_em >= NOW(6) - INTERVAL 3 SECOND
                        AND p.estavel = 1 THEN 1 ELSE 0 END AS estavel,
                    CASE WHEN p.atualizado_em >= NOW(6) - INTERVAL 60 SECOND THEN 1 ELSE 0 END AS ligada
               FROM desktop_agent_devices d
               JOIN `user` u ON u.id = d.id_user AND u.id_restaurante = d.id_restaurante
               LEFT JOIN desktop_agent_pesagens p ON p.id_dispositivo = d.id
                    AND p.id_restaurante = d.id_restaurante
              WHERE d.id_restaurante = ? AND d.revogado_em IS NULL AND d.expira_em > NOW()
                AND u.ativo = 1 AND u.excluido_em IS NULL AND u.cargo IN (\'gerente\', \'caixa\')
              ORDER BY d.nome, d.id LIMIT 50'
        );
        $q->execute([$restauranteId]);
        $devices = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $devices[] = [
                'id' => (int) $row['id'],
                'nome' => (string) $row['nome'],
                'peso_kg' => $row['peso_kg'] === null ? null : (string) $row['peso_kg'],
                'estavel' => (int) $row['estavel'] === 1,
                // Agent com balanca ativa manda leitura a cada ~1 s; sem noticia ha 60 s = desligada.
                'ligada' => (int) $row['ligada'] === 1,
            ];
        }
        return $devices;
    }
}
