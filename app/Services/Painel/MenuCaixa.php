<?php

declare(strict_types=1);

namespace Cardapim\Services\Painel;

/** Menu lateral do modo caixa. A fila de delivery fica logo abaixo do menu (layouts/caixa.php). */
final class MenuCaixa
{
    /** chave => [rotulo, icone, caminho dentro de /{slug}/caixa] */
    public const ITENS = [
        'caixa' => ['Caixa', 'wallet', ''],
        'mesas' => ['Mesas e comandas', 'armchair', 'mesas'],
        'delivery' => ['Delivery', 'bike', 'delivery'],
        'movimentos' => ['Entradas e saídas', 'arrow-left-right', 'movimentos'],
        'historico' => ['Fechamentos', 'rotate-ccw-clock', 'historico'],
        'gerenciar-mesas' => ['Cadastro de mesas', 'settings', 'mesas/gerenciar'],
    ];

    public const GRUPOS = [
        '' => ['caixa'],
        'Atendimento' => ['mesas', 'delivery'],
        'Dinheiro' => ['movimentos', 'historico'],
        'Ajustes' => ['gerenciar-mesas'],
    ];

    public static function url(string $slug, string $chave): string
    {
        $caminho = self::ITENS[$chave][2] ?? '';
        return '/' . rawurlencode($slug) . '/caixa' . ($caminho !== '' ? '/' . $caminho : '');
    }
}
