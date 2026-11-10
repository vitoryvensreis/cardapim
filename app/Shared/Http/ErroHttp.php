<?php

declare(strict_types=1);

namespace Cardapim\Shared\Http;

use RuntimeException;

/** Falha de rede/tempo esgotado ao chamar um servico externo (sem resposta HTTP). */
final class ErroHttp extends RuntimeException
{
}
