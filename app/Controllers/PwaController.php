<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Response;
use Cardapim\Modules\Marca\MarcaUpload;
use Cardapim\Services\Configuracoes;

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
            'icons' => self::icones(),
        ];
        return new Response(
            json_encode($manifesto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/manifest+json; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600'],
        );
    }

    /** Icone enviado em Configuracoes (com os tamanhos gerados) ou os arquivos originais. @return list<array<string,string>> */
    public static function icones(): array
    {
        $enviado = Configuracoes::logoOuPadrao('icone_app');
        $v = MarcaUpload::variantesIcone($enviado);
        if ($v !== null) {
            return [
                ['src' => $v['192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $enviado, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $v['maskable'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];
        }
        return [
            ['src' => '/assets/img/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/assets/img/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/assets/img/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    /** Icone do iPhone (180 px). */
    public static function iconeApple(): string
    {
        $v = MarcaUpload::variantesIcone(Configuracoes::logoOuPadrao('icone_app'));
        return $v['apple'] ?? \Cardapim\Core\AssetUrl::asset('img/pwa/apple-touch-icon.png');
    }

    /**
     * Logo da tela offline: a enviada em Configuracoes (preta, para fundo claro; sem ela, a colorida).
     * Os arquivos em /assets/img sao so a reserva para banco fora do ar.
     */
    public static function logoOffline(): string
    {
        foreach (['logo_preta', 'logo_colorida'] as $chave) {
            $logo = Configuracoes::logoOuPadrao($chave);
            if ($logo !== Configuracoes::LOGO_FALLBACK[$chave]) {
                return $logo;
            }
        }
        return Configuracoes::LOGO_FALLBACK['logo_preta'];
    }

    /** Tela "Sem conexao" com a marca atual (o service worker guarda esta pagina e a logo). */
    public function offline(): Response
    {
        $html = (static function (string $logo, string $icone, string $marca): string {
            ob_start();
            require dirname(__DIR__) . '/Views/pwa/offline.php';
            return (string) ob_get_clean();
        })(self::logoOffline(), Configuracoes::logoOuPadrao('icone'), marca());
        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function serviceWorker(): Response
    {
        $arquivo = dirname(__DIR__, 2) . '/public/assets/js/sw.js';
        $codigo = is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
        // Logo atual na lista guardada; trocar a logo troca a versao e o navegador baixa de novo.
        $logo = self::logoOffline();
        $codigo = str_replace(['__LOGO_OFFLINE__', '__VERSAO_MARCA__'], [
            trim(json_encode($logo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), '"'), substr(hash('sha256', $logo), 0, 10),
        ], $codigo);
        return new Response($codigo, $codigo === '' ? 404 : 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
