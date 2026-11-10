<?php

declare(strict_types=1);

namespace Cardapim\Modules\Inventory;

use RuntimeException;

/** Venda recusada: algum insumo nao tem quantidade suficiente (nada foi baixado). */
final class EstoqueInsuficiente extends RuntimeException
{
    /** @param list<array{id_insumo:int, nome:string, necessario:string, disponivel:string, unidade:string}> $faltas */
    public function __construct(public readonly array $faltas)
    {
        $nomes = implode(', ', array_column($faltas, 'nome'));
        parent::__construct('Estoque insuficiente para: ' . $nomes . '.');
    }
}
