<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Response;

/**
 * Aplicativo instalavel (PWA) da equipe: manifesto (/pwa/manifesto) e service worker (/pwa/sw) servidos pelo PHP para garantir o tipo certo
 * e "sem cache" (o navegador precisa ver a versao nova do service worker logo depois de cada deploy).
 * O app abre no /login, que leva cada cargo para a sua area (gerencia, caixa, entregas, painel).
 */
final class PwaController
{
    public function manifesto(): Response
    {
        $manifesto = [
            'id' => '/login',
            'name' => marca(),
            'short_name' => marca(),
            'description' => 'Gestão do restaurante: caixa, mesas, pedidos, entregas e estoque.',
            'lang' => 'pt-BR',
            'dir' => 'ltr',
            'start_url' => '/login?origem=app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#651424',
            'theme_color' => '#651424',
            'categories' => ['business', 'food', 'productivity'],
            'icons' => [
                ['src' => '/assets/img/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/img/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/img/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return new Response(
            json_encode($manifesto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/manifest+json; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600'],
        );
    }

    public function serviceWorker(): Response
    {
        $arquivo = dirname(__DIR__, 2) . '/public/assets/js/sw.js';
        $codigo = is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
        return new Response($codigo, $codigo === '' ? 404 : 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
