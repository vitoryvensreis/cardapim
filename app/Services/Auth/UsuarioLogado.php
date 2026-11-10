<?php

declare(strict_types=1);

namespace Cardapim\Services\Auth;

/** Usuario autenticado, recarregado do banco a cada requisicao. */
final class UsuarioLogado
{
    public function __construct(
        public readonly int $id,
        public readonly string $cargo,
        public readonly string $nome,
        public readonly string $sobrenome,
        public readonly bool $ativo,
        public readonly ?int $idRestaurante,
        public readonly ?string $restauranteSlug,
        public readonly ?string $restauranteNome,
        public readonly ?string $restauranteStatus,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function deLinha(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['cargo'],
            (string) $row['nome'],
            (string) $row['sobrenome'],
            (int) $row['ativo'] === 1,
            $row['id_restaurante'] !== null ? (int) $row['id_restaurante'] : null,
            $row['restaurante_slug'] ?? null,
            $row['restaurante_nome'] ?? null,
            $row['restaurante_status'] ?? null,
        );
    }

    public function urlRestaurante(string $area): string
    {
        return $this->restauranteSlug !== null ? '/' . rawurlencode($this->restauranteSlug) . '/' . $area : '/minha-conta';
    }

    public function nomeCompleto(): string
    {
        return trim($this->nome . ' ' . $this->sobrenome);
    }
}
