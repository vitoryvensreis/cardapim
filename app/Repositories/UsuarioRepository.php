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

    /**
     * Dados necessarios ao login, por e-mail ou nome de usuario, com o restaurante vinculado.
     * @return array<string,mixed>|null
     */
    public function buscarParaLogin(string $identificacao): ?array
    {
        $coluna = str_contains($identificacao, '@') ? 'u.email' : 'u.usuario';
        $query = $this->db->prepare(self::SELECT_SESSAO . ", u.senha_hash, u.dois_fatores FROM `user` u
            LEFT JOIN restaurantes r ON r.id = u.id_restaurante
            WHERE $coluna = ? AND u.excluido_em IS NULL LIMIT 1");
        $query->execute([$identificacao]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Recarrega o usuario da sessao a cada requisicao (desativacao vale na hora). */
    public function buscarParaSessao(int $id): ?array
    {
        $query = $this->db->prepare(self::SELECT_SESSAO . " FROM `user` u
            LEFT JOIN restaurantes r ON r.id = u.id_restaurante
            WHERE u.id = ? LIMIT 1");
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function registrarAcesso(int $id, string $quando): void
    {
        $this->db->prepare('UPDATE `user` SET ultimo_acesso = ? WHERE id = ?')->execute([$quando, $id]);
    }

    public function atualizarHash(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE `user` SET senha_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    private const SELECT_SESSAO = 'SELECT u.id, u.cargo, u.id_restaurante, u.nome, u.sobrenome, u.ativo,
        r.slug AS restaurante_slug, r.nome AS restaurante_nome, r.status_rest AS restaurante_status';

    public function emailExiste(string $email): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM `user` WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        return $query->fetchColumn() !== false;
    }
}
