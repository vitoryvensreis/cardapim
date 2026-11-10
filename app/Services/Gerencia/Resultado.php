<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

/** Resultado de uma acao do painel: sucesso com mensagem, ou erros por campo para reexibir o formulario. */
final class Resultado
{
    /** @param array<string,string> $erros campo => mensagem */
    private function __construct(
        public readonly bool $ok,
        public readonly string $mensagem,
        public readonly array $erros = [],
        public readonly ?int $id = null,
    ) {
    }

    public static function sucesso(string $mensagem, ?int $id = null): self
    {
        return new self(true, $mensagem, [], $id);
    }

    /** @param array<string,string> $erros */
    public static function falha(string $mensagem, array $erros = []): self
    {
        return new self(false, $mensagem, $erros);
    }
}
