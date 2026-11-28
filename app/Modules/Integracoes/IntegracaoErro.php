<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use RuntimeException;

/** Erro de integracao com mensagem pronta para mostrar ao gerente (sem dados sensiveis). */
final class IntegracaoErro extends RuntimeException
{
    /** Codigo do erro quando o destino nunca vai funcionar (numero invalido ou sem WhatsApp): nao adianta tentar de novo. */
    public const DESTINO_INVALIDO = 422;

    public function destinoInvalido(): bool
    {
        return $this->getCode() === self::DESTINO_INVALIDO;
    }
}
