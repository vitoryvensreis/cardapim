<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Services\Seo\CartaoCompartilhamento;
use Cardapim\Services\Seo\Seo;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Shared\Slug;

/**
 * Para buscadores e redes sociais: /robots.txt, /sitemap.xml e as imagens dos links compartilhados
 * (/midia/cartao para a plataforma e /midia/cartao/{slug} para cada restaurante).
 */
final class SeoController
{
    /** Paginas publicas fixas que entram no mapa do site. */
    private const PAGINAS = ['/', '/catalogo', '/cadastro', '/privacidade', '/termos-de-uso', '/cookies', '/exclusao-de-dados', '/tratamento-de-dados'];

    public function robots(): Response
    {
        $linhas = [
            'User-agent: *',
            'Allow: /',
            // Areas da equipe e do adm: nao tem o que mostrar no Google (e ja exigem login).
            'Disallow: /painel',
            'Disallow: /api/',
            'Disallow: /minha-conta',
            'Disallow: /login/verificacao',
            'Disallow: /recuperar-senha',
            'Disallow: /pwa/',
            'Disallow: /*/gerencia',
            'Disallow: /*/caixa',
            'Disallow: /*/garcom',
            'Disallow: /*/entregas',
        ];
        if (Seo::base() !== '') {
            $linhas[] = '';
            $linhas[] = 'Sitemap: ' . Seo::absoluta('/sitemap.xml');
        }
        return new Response(implode("\n", $linhas) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(): Response
    {
        $urls = array_map(static fn (string $p): array => ['loc' => Seo::absoluta($p), 'lastmod' => null], self::PAGINAS);
        try {
            foreach ((new RestauranteRepository(Database::connect()))->listarParaMapa() as $r) {
                $urls[] = ['loc' => Seo::absoluta('/' . rawurlencode($r['slug'])), 'lastmod' => $r['atualizado']];
            }
        } catch (\Throwable $erro) {
            // Sem banco o mapa sai so com as paginas fixas.
            error_log('[Cardapim] Mapa do site sem restaurantes: ' . get_class($erro));
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($u['lastmod'] !== null ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '') . "</url>\n";
        }
        $xml .= "</urlset>\n";
        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** GET /midia/cartao - imagem automatica do link da plataforma (ou a enviada no painel). */
    public function cartao(): Response
    {
        $enviada = Seo::imagemEnviada();
        if ($enviada !== null) {
            return Response::redirect(\Cardapim\Shared\MidiaUrl::publica($enviada), 302);
        }
        try {
            $bytes = CartaoCompartilhamento::plataforma(Seo::dadosCartaoPlataforma());
        } catch (\Throwable $erro) {
            error_log('[Cardapim] Cartao da plataforma indisponivel: ' . get_class($erro));
            $bytes = null;
        }
        return $this->imagem($bytes);
    }

    /** GET /midia/cartao/{slug} - imagem do link do cardapio de um restaurante ativo. */
    public function cartaoRestaurante(string $slug): Response
    {
        if (!Slug::formatoValido($slug) || Slug::reservado($slug)) {
            return $this->naoEncontrada();
        }
        try {
            $db = Database::connect();
            $restaurante = (new RestauranteRepository($db))->buscarPublicoPorSlug($slug);
            if ($restaurante === null) {
                return $this->naoEncontrada();
            }
            $local = (string) ((new CardapioPublico($db))->informacoes($restaurante['id'])['bairro_cidade'] ?? '');
            $bytes = CartaoCompartilhamento::restaurante(Seo::dadosCartaoRestaurante($restaurante, $local));
        } catch (\Throwable $erro) {
            error_log('[Cardapim] Cartao do restaurante indisponivel: ' . get_class($erro));
            $bytes = null;
        }
        return $this->imagem($bytes);
    }

    private function imagem(?string $bytes): Response
    {
        if ($bytes === null || $bytes === '') {
            // Sem GD no servidor: o icone do app ainda mostra a marca no link.
            return Response::redirect(\Cardapim\Controllers\PwaController::icones()[1]['src'], 302);
        }
        return new Response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            // O endereco muda (?v=) quando o cartao muda; um dia de cache para quem pedir sem o ?v=.
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline',
        ]);
    }

    private function naoEncontrada(): Response
    {
        return new Response('Imagem não encontrada.', 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
