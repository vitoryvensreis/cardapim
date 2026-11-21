<?php

declare(strict_types=1);

namespace Cardapim\Repositories\Gerencia;

use Cardapim\Shared\Esquema;
use PDO;

/**
 * O que o garcom pode fazer depois de lancar (remover item, cancelar comanda) e se fecha a conta.
 * Sem linha (ou sem tabela): remover e cancelar bloqueados; fechar a conta liberado (como sempre foi).
 */
final class PermissoesGarcomRepository
{
    public const CAMPOS = ['remover_itens', 'cancelar_comanda', 'fechar_conta'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{remover_itens:int,cancelar_comanda:int,fechar_conta:int} */
    public function buscar(int $idRestaurante): array
    {
        $fechar = Esquema::tem($this->db, 'garcom_fechar');
        try {
            $q = $this->db->prepare('SELECT remover_itens, cancelar_comanda' . ($fechar ? ', fechar_conta' : '') . ' FROM restaurante_garcom_permissoes WHERE id_restaurante = ?');
            $q->execute([$idRestaurante]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            $row = false; // tabela ainda nao criada (migration 20261121400000 pendente): tudo bloqueado
        }
        return [
            'remover_itens' => (int) ($row['remover_itens'] ?? 0),
            'cancelar_comanda' => (int) ($row['cancelar_comanda'] ?? 0),
            'fechar_conta' => (int) ($row['fechar_conta'] ?? 1),
        ];
    }

    /** @param array{remover_itens:int,cancelar_comanda:int,fechar_conta:int} $p */
    public function salvar(int $idRestaurante, array $p): void
    {
        if (!Esquema::tem($this->db, 'garcom_fechar')) {
            $this->db->prepare('INSERT INTO restaurante_garcom_permissoes (id_restaurante, remover_itens, cancelar_comanda) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE remover_itens = VALUES(remover_itens), cancelar_comanda = VALUES(cancelar_comanda), atualizado_em = CURRENT_TIMESTAMP')
                ->execute([$idRestaurante, $p['remover_itens'], $p['cancelar_comanda']]);
            return;
        }
        $this->db->prepare('INSERT INTO restaurante_garcom_permissoes (id_restaurante, remover_itens, cancelar_comanda, fechar_conta) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE remover_itens = VALUES(remover_itens), cancelar_comanda = VALUES(cancelar_comanda),
                fechar_conta = VALUES(fechar_conta), atualizado_em = CURRENT_TIMESTAMP')
            ->execute([$idRestaurante, $p['remover_itens'], $p['cancelar_comanda'], $p['fechar_conta']]);
    }
}
