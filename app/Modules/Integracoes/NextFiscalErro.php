<?php
declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use RuntimeException;

/** Mensagem devolvida pela própria NeXT, já higienizada; pode ser exibida ao usuário. */
final class NextFiscalErro extends RuntimeException
{
    public function __construct(string $mensagem, public readonly string $codigoFornecedor = '')
    {
        parent::__construct($mensagem);
    }

    public function paraUsuario(): string
    {
        return 'A NeXT informou: ' . $this->getMessage();
    }
}
