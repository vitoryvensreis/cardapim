<?php

declare(strict_types=1);

namespace Cardapim\Repositories;

use PDO;

final class UsuarioRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Comparacao pela collation da coluna (sem diferenciar maiusculas). */
    public function usuarioExiste(string $usuario): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM `user` WHERE usuario = ? LIMIT 1');
        $query->execute([$usuario]);
        return $query->fetchColumn() !== false;
    }

    public function emailExiste(string $email): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM `user` WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        return $query->fetchColumn() !== false;
    }
}
