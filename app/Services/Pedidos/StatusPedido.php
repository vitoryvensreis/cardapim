<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

/** Nomes e textos dos status do pedido, para o cliente e para a equipe. */
final class StatusPedido
{
    public const ROTULOS = [
        'aguardando_pagamento' => 'Aguardando pagamento',
        'aberto' => 'Conta aberta',
        'recebido' => 'Pedido recebido',
        'em_preparo' => 'Em preparo',
        'pronto' => 'Pronto',
        'saiu_entrega' => 'Saiu para entrega',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];

    public const FORMAS = [
        'pix_online' => 'Pix pelo app',
        'pix_entrega' => 'Pix na entrega',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'dinheiro' => 'Dinheiro',
        'misto' => 'Mais de uma forma',
    ];

    /** Etapas mostradas ao cliente (linha do tempo). @return list<string> */
    public static function etapas(string $tipo): array
    {
        return $tipo === 'entrega'
            ? ['recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido']
            : ['recebido', 'em_preparo', 'pronto', 'concluido'];
    }

    public static function rotulo(string $status, string $tipo = 'entrega'): string
    {
        if ($status === 'pronto' && $tipo === 'retirada') {
            return 'Pronto para retirar';
        }
        if ($status === 'concluido') {
            return $tipo === 'entrega' ? 'Entregue' : 'Retirado';
        }
        return self::ROTULOS[$status] ?? $status;
    }

    public static function forma(string $forma, string $tipo = 'entrega'): string
    {
        if ($tipo === 'retirada') {
            return match ($forma) {
                'pix_entrega' => 'Pix na retirada',
                'debito' => 'Cartão de débito na retirada',
                'credito' => 'Cartão de crédito na retirada',
                'dinheiro' => 'Dinheiro na retirada',
                default => self::FORMAS[$forma] ?? $forma,
            };
        }
        return match ($forma) {
            'debito' => 'Cartão de débito na entrega',
            'credito' => 'Cartão de crédito na entrega',
            'dinheiro' => 'Dinheiro na entrega',
            default => self::FORMAS[$forma] ?? $forma,
        };
    }
}
