<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use PDO;

/** O que o garcom pode fazer depois de lancar (remover item, cancelar comanda). Sem linha (ou sem tabela) = tudo bloqueado. */
final class PermissoesGarcomRepository
{
    public const CAMPOS = ['remover_itens', 'cancelar_comanda'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{remover_itens:int,cancelar_comanda:int} */
    public function buscar(int $idRestaurante): array
    {
        try {
            $q = $this->db->prepare('SELECT remover_itens, cancelar_comanda FROM restaurante_garcom_permissoes WHERE id_restaurante = ?');
            $q->execute([$idRestaurante]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            $row = false; // tabela ainda nao criada (migration 20261121400000 pendente): tudo bloqueado
        }
        return [
            'remover_itens' => (int) ($row['remover_itens'] ?? 0),
            'cancelar_comanda' => (int) ($row['cancelar_comanda'] ?? 0),
        ];
    }

    /** @param array{remover_itens:int,cancelar_comanda:int} $p */
    public function salvar(int $idRestaurante, array $p): void
    {
        $this->db->prepare('INSERT INTO restaurante_garcom_permissoes (id_restaurante, remover_itens, cancelar_comanda) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE remover_itens = VALUES(remover_itens), cancelar_comanda = VALUES(cancelar_comanda), atualizado_em = CURRENT_TIMESTAMP')
            ->execute([$idRestaurante, $p['remover_itens'], $p['cancelar_comanda']]);
    }
}
