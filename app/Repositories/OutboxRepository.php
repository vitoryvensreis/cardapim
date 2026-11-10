<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/** Tabela outbox_eventos: eventos gravados antes de irem para o tempo real. */
final class OutboxRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function inserir(?int $idRestaurante, string $canal, string $tipo, string $dadosJson, string $agora): int
    {
        $q = $this->db->prepare('INSERT INTO outbox_eventos (id_restaurante, canal, tipo, dados, criado_em) VALUES (?, ?, ?, ?, ?)');
        $q->execute([$idRestaurante, $canal, $tipo, $dadosJson, $agora]);
        return (int) $this->db->lastInsertId();
    }

    public function ultimoId(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM outbox_eventos')->fetchColumn();
    }

    /**
     * Eventos novos de todos os restaurantes, em ordem.
     * @return list<array{id:int,id_restaurante:?int,canal:string,tipo:string,dados:string,criado_em:string}>
     */
    public function depoisDe(int $id, int $limite): array
    {
        $q = $this->db->prepare('SELECT id, id_restaurante, canal, tipo, dados, criado_em FROM outbox_eventos WHERE id > ? ORDER BY id LIMIT ' . max(1, min($limite, 1000)));
        $q->execute([$id]);
        return array_map(self::linha(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Eventos perdidos por um navegador que reconectou (so do restaurante e dos canais dele).
     * @param list<string> $canais
     * @return list<array{id:int,id_restaurante:?int,canal:string,tipo:string,dados:string,criado_em:string}>
     */
    public function doRestauranteDepoisDe(int $idRestaurante, array $canais, int $id, int $limite): array
    {
        if ($canais === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($canais), '?'));
        $q = $this->db->prepare(
            'SELECT id, id_restaurante, canal, tipo, dados, criado_em FROM outbox_eventos'
            . ' WHERE id_restaurante = ? AND id > ? AND canal IN (' . $marcas . ') ORDER BY id LIMIT ' . max(1, min($limite, 1000)),
        );
        $q->execute([$idRestaurante, $id, ...$canais]);
        return array_map(self::linha(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function marcarPublicados(int $deId, int $ateId, string $agora): void
    {
        $q = $this->db->prepare('UPDATE outbox_eventos SET publicado_em = ? WHERE id > ? AND id <= ? AND publicado_em IS NULL');
        $q->execute([$agora, $deId, $ateId]);
    }

    /** Apaga eventos mais velhos que a data (ja entregues ou nao: a tela recarrega o estado pela API). */
    public function apagarAntesDe(string $data): int
    {
        $q = $this->db->prepare('DELETE FROM outbox_eventos WHERE criado_em < ?');
        $q->execute([$data]);
        return $q->rowCount();
    }

    /** @param array<string,mixed> $l */
    private static function linha(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'id_restaurante' => $l['id_restaurante'] !== null ? (int) $l['id_restaurante'] : null,
            'canal' => (string) $l['canal'],
            'tipo' => (string) $l['tipo'],
            'dados' => (string) $l['dados'],
            'criado_em' => (string) $l['criado_em'],
        ];
    }
}
