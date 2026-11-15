<?php

declare(strict_types=1);

namespace Cardapim\Services\Cozinha;

use Cardapim\Repositories\Gerencia\CozinhaRepository;
use PDO;

/** Dados da cozinha, sem ações de mudança de estado ou envio de avisos. */
final class PainelCozinha
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante)
    {
    }

    /** @return array{pedidos:list<array<string,mixed>>,total:int} */
    public function painel(): array
    {
        $pedidos = (new CozinhaRepository($this->db))->pedidos($this->idRestaurante);
        return ['pedidos' => $pedidos, 'total' => count($pedidos)];
    }
}
