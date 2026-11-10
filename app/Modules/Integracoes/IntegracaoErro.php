<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use RuntimeException;

/** Erro de integracao com mensagem pronta para mostrar ao gerente (sem dados sensiveis). */
final class IntegracaoErro extends RuntimeException
{
}
