<?php

declare(strict_types=1);

namespace Cardapim\Shared\Http;

/** Resposta de uma chamada HTTP a um servico externo. */
final class RespostaHttp
{
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly string $tipo = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed> corpo JSON decodificado ([] se nao for JSON) */
    public function json(): array
    {
        $dados = json_decode($this->corpo, true);
        return is_array($dados) ? $dados : [];
    }
}
