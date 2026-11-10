<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

final class PlanoRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Exibe somente planos contrataveis no catalogo publico.
     * O preco retornado e o total do periodo, nao uma mensalidade calculada.
     *
     * @return array<int, array{id: int|string, nome: string, descricao: ?string, valor: string, periodo: string}>
     */

    /**
     * Valida novamente o plano escolhido pela URL, sem confiar no id da interface.
     *
     * @return array<string,mixed>|null
     */
    public function buscarPublicoPorId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $query = $this->db->prepare(<<<'SQL'
SELECT id, nome, descricao, valor, periodo
FROM planos
WHERE id = ? AND ativo = 1 AND visibilidade = 1
LIMIT 1
SQL);
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listarPublicos(): array
    {
        $query = $this->db->query(<<<'SQL'
SELECT id, nome, descricao, valor, periodo
FROM planos
WHERE ativo = 1 AND visibilidade = 1
ORDER BY FIELD(periodo, 'mensal', 'semestral', 'anual'), valor ASC, id ASC
SQL);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
