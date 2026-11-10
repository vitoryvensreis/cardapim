<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

/**
 * Canais do tempo real e quem pode ouvir cada um (fonte unica, conferida no servidor WebSocket).
 * Um canal e sempre "do restaurante": o servidor so entrega eventos do restaurante do ticket.
 */
final class Canais
{
    /** Todos os canais existentes. Novo canal: incluir aqui e na lista dos cargos que podem ouvir. */
    public const TODOS = [
        'geral',        // avisos para toda a equipe do restaurante
        'pedidos',      // pedidos criados/alterados/cancelados
        'cozinha',      // comandas e itens prontos (KDS)
        'caixa',        // abertura/fechamento e pagamentos do caixa
        'mesas',        // mesas e comandas
        'entregas',     // entregas e entregadores
        'estoque',      // insumos, produtos e categorias
        'equipe',       // contas da equipe
        'integracoes',  // Asaas, iFood
        'whatsapp',     // conexao e (depois) conversas do WhatsApp
        'financeiro',   // relatorios e valores
    ];

    /** O que cada cargo pode ouvir. Cargo fora da lista nao ouve nada. */
    private const POR_CARGO = [
        'adm_master' => self::TODOS,
        'gerente' => self::TODOS,
        'caixa' => ['geral', 'pedidos', 'cozinha', 'caixa', 'mesas', 'entregas', 'estoque'],
        'garcom' => ['geral', 'pedidos', 'cozinha', 'mesas'],
        'motoqueiro' => ['geral', 'entregas'],
    ];

    /** Canais que cada secao do painel do gerente acompanha (para avisar "algo mudou"). */
    private const POR_SECAO = [
        'estoque' => ['estoque'],
        'integracoes' => ['integracoes', 'whatsapp'],
        'equipe' => ['equipe'],
    ];

    /** @return list<string> */
    public static function permitidos(string $cargo): array
    {
        return self::POR_CARGO[$cargo] ?? [];
    }

    public static function existe(string $canal): bool
    {
        return in_array($canal, self::TODOS, true);
    }

    /**
     * Interseccao entre o pedido do navegador e o que o cargo pode ouvir (nunca confia no navegador).
     * @param list<mixed> $pedidos
     * @return list<string>
     */
    public static function filtrar(string $cargo, array $pedidos): array
    {
        $permitidos = self::permitidos($cargo);
        $saida = [];
        foreach ($pedidos as $canal) {
            if (is_string($canal) && in_array($canal, $permitidos, true) && !in_array($canal, $saida, true)) {
                $saida[] = $canal;
            }
        }
        return $saida;
    }

    /** @return list<string> */
    public static function daSecao(?string $secao): array
    {
        return self::POR_SECAO[(string) $secao] ?? [];
    }
}
