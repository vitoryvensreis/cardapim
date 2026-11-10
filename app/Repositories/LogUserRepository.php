<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

/** Auditoria em log_user. Nunca registrar senhas, tokens ou documentos. */
final class LogUserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function registrar(string $titulo, string $descricao, ?int $idUser, ?string $ip): void
    {
        $this->db->prepare('INSERT INTO log_user (titulo, descricao, id_user, ip) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($titulo, 0, 200), $descricao, $idUser, $ip !== null ? substr($ip, 0, 45) : null]);
    }
}
