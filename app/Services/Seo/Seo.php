<?php

declare(strict_types=1);

namespace Cardapim\Services\Seo;

use Cardapim\Core\AssetUrl;
use Cardapim\Modules\Marca\MarcaUpload;
use Cardapim\Modules\Restaurante\LogoUpload;
use Cardapim\Services\Configuracoes;
use Cardapim\Shared\MidiaUrl;

/**
 * Google e redes sociais: titulo, descricao, imagem do link compartilhado, endereco oficial (canonical) e dados
 * estruturados (JSON-LD). Os textos e a imagem da plataforma vem de Configuracoes (painel do adm_master).
 * Usado por partials/head.php; as paginas so informam o que e delas (ex.: o restaurante do cardapio).
 */
final class Seo
{
    public const TITULO_MAX = 70;
    public const DESCRICAO_MAX = 160;
    public const FRASE_MAX = 90;

    public const FRASE_PADRAO = 'Pedidos, mesas, caixa, cozinha e delivery em um só lugar.';
    public const DESCRICAO_PADRAO = 'Organize pedidos, mesas, comandas, caixa, cozinha e delivery em um só lugar. Cardápio online com pedidos pelo celular, simples desde o primeiro dia.';

    public static function tituloPadrao(): string
    {
        return marca() . ' | Sistema para restaurantes, bares e delivery';
    }

    /** Titulo da pagina inicial (Google e redes sociais). */
    public static function tituloInicio(): string
    {
        return self::texto('seo_titulo', self::tituloPadrao(), self::TITULO_MAX);
    }

    public static function descricaoInicio(): string
    {
        return self::texto('seo_descricao', self::DESCRICAO_PADRAO, self::DESCRICAO_MAX);
    }

    /** Frase que aparece no cartao automatico da plataforma. */
    public static function frase(): string
    {
        return self::texto('frase_compartilhamento', self::FRASE_PADRAO, self::FRASE_MAX);
    }

    /** Codigo da verificacao do Google Search Console (so letras, numeros, _ e -). */
    public static function verificacaoGoogle(): string
    {
        $v = self::texto('google_verificacao', '', 100);
        return preg_match('/\A[A-Za-z0-9_-]{10,100}\z/', $v) === 1 ? $v : '';
    }

    /** Endereco do site sem barra no fim, ou '' se o APP_URL nao estiver configurado. */
    public static function base(): string
    {
        try {
            return AssetUrl::base();
        } catch (\Throwable) {
            return '';
        }
    }

    /** "/x" -> "https://site/x". Endereco ja completo volta como veio. */
    public static function absoluta(string $caminho): string
    {
        if (str_starts_with($caminho, 'https://') || str_starts_with($caminho, 'http://')) {
            return $caminho;
        }
        return self::base() . '/' . ltrim($caminho, '/');
    }

    /** Dominio para mostrar ao publico, ex.: "cardapim.com.br". */
    public static function dominio(): string
    {
        $host = (string) parse_url(self::base(), PHP_URL_HOST);
        return preg_replace('/\Awww\./', '', $host) ?? $host;
    }

    /** Endereco desta pagina sem os parametros (?busca=...), para og:url e canonical. */
    public static function urlAtual(): string
    {
        $caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($caminho === '' || preg_match('#\A/[A-Za-z0-9/_.~%-]*\z#', $caminho) !== 1) {
            $caminho = '/';
        }
        return self::absoluta($caminho);
    }

    /**
     * Imagem do link da plataforma: a enviada pelo adm_master ou o cartao automatico (/midia/cartao).
     * @return array{url:string, largura:int, altura:int, tipo:string, alt:string}
     */
    public static function imagemPlataforma(): array
    {
        $enviada = self::imagemEnviada();
        $alt = marca() . ' · ' . self::frase();
        if ($enviada !== null) {
            return ['url' => self::absoluta(MidiaUrl::publica($enviada)), 'largura' => 1200, 'altura' => 630, 'tipo' => 'image/jpeg', 'alt' => $alt];
        }
        return ['url' => self::absoluta('/midia/cartao?v=' . CartaoCompartilhamento::versaoPlataforma(self::dadosCartaoPlataforma())),
            'largura' => 1200, 'altura' => 630, 'tipo' => 'image/jpeg', 'alt' => $alt];
    }

    /**
     * Imagem do link do cardapio de um restaurante (cartao com a logo, o nome e a cidade).
     * @param array{nome:string, slug:string, logo_path:?string} $restaurante
     * @return array{url:string, largura:int, altura:int, tipo:string, alt:string}
     */
    public static function imagemRestaurante(array $restaurante, string $local): array
    {
        $v = CartaoCompartilhamento::versaoRestaurante(self::dadosCartaoRestaurante($restaurante, $local));
        return ['url' => self::absoluta('/midia/cartao/' . rawurlencode($restaurante['slug']) . '?v=' . $v),
            'largura' => 1200, 'altura' => 630, 'tipo' => 'image/jpeg', 'alt' => 'Cardápio de ' . $restaurante['nome']];
    }

    /** Arquivo da imagem enviada no painel, se houver e existir no disco. */
    public static function imagemEnviada(): ?string
    {
        $caminho = self::texto('imagem_compartilhamento', '', 200);
        $disco = $caminho === '' ? null : MarcaUpload::arquivoDe($caminho);
        return $disco !== null && is_file($disco) ? MidiaUrl::armazenada($caminho) : null;
    }

    /** @return array{nome:string, frase:string, icone:?string, endereco:string} */
    public static function dadosCartaoPlataforma(): array
    {
        // So icones ENVIADOS no painel (icone do app ou favicon em PNG/JPG/WebP). Os arquivos originais do repositorio
        // nao entram: podem ser de uma marca antiga. Sem nenhum, o cartao sai so com o nome e a frase.
        $disco = null;
        foreach (['icone_app', 'icone'] as $chave) {
            $arquivo = MarcaUpload::arquivoDe(Configuracoes::logoOuPadrao($chave));
            if ($arquivo !== null && is_file($arquivo) && preg_match('/\.(?:png|jpg|webp)\z/', $arquivo) === 1) {
                $disco = $arquivo;
                break;
            }
        }
        return ['nome' => marca(), 'frase' => self::frase(), 'icone' => $disco, 'endereco' => self::dominio()];
    }

    /**
     * @param array{nome:string, slug:string, logo_path:?string} $restaurante
     * @return array{nome:string, local:string, logo:?string, endereco:string, marca:string}
     */
    public static function dadosCartaoRestaurante(array $restaurante, string $local): array
    {
        // So logos guardadas no servidor: nunca baixa imagem de outro site.
        $caminho = $restaurante['logo_path'];
        $logo = $caminho !== null ? LogoUpload::arquivoDe($caminho) : null;
        return [
            'nome' => $restaurante['nome'],
            'local' => $local,
            'logo' => $logo,
            'endereco' => self::dominio() . '/' . $restaurante['slug'],
            'marca' => marca(),
        ];
    }

    /**
     * Tipo da imagem pelo endereco (favicon pode ser SVG ou PNG enviado no painel).
     */
    public static function tipoImagem(string $url): string
    {
        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));
        return match (true) {
            (bool) preg_match('/[.-]svg\z/', $caminho) => 'image/svg+xml',
            (bool) preg_match('/[.-]png\z/', $caminho) => 'image/png',
            (bool) preg_match('/[.-]jpe?g\z/', $caminho) => 'image/jpeg',
            (bool) preg_match('/[.-]webp\z/', $caminho) => 'image/webp',
            (bool) preg_match('/\.ico\z/', $caminho) => 'image/x-icon',
            default => 'image/png',
        };
    }

    /** JSON-LD seguro para ir dentro de <script type="application/ld+json"> (sem "</script>" possivel). */
    public static function jsonLd(array $dados): string
    {
        return (string) json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Organizacao e site, para a pagina inicial. @return list<array<string,mixed>> */
    public static function jsonLdInicio(): array
    {
        $url = self::absoluta('/');
        $icone = Configuracoes::logoOuPadrao('icone_app');
        return [
            ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => marca(), 'url' => $url, 'logo' => self::absoluta(MidiaUrl::publica($icone))],
            ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => marca(), 'url' => $url, 'inLanguage' => 'pt-BR', 'description' => self::descricaoInicio()],
        ];
    }

    /**
     * Restaurante (nome, endereco, telefone, cardapio), para o cardapio publico.
     * @param array{nome:string, slug:string, logo_path:?string} $restaurante
     * @param array<string,mixed> $info CardapioPublico::informacoes()
     * @return list<array<string,mixed>>
     */
    public static function jsonLdRestaurante(array $restaurante, array $info, string $imagem): array
    {
        $url = self::absoluta('/' . rawurlencode($restaurante['slug']));
        $local = is_array($info['local'] ?? null) ? $info['local'] : [];
        $dados = ['@context' => 'https://schema.org', '@type' => 'Restaurant', 'name' => $restaurante['nome'], 'url' => $url, 'hasMenu' => $url, 'image' => $imagem];
        $endereco = array_filter([
            'streetAddress' => (string) ($local['rua'] ?? ''),
            'addressLocality' => (string) ($local['cidade'] ?? ''),
            'addressRegion' => (string) ($local['estado'] ?? ''),
            'postalCode' => (string) ($local['cep'] ?? ''),
        ], static fn (string $v): bool => $v !== '');
        if ($endereco !== []) {
            $dados['address'] = ['@type' => 'PostalAddress', ...$endereco, 'addressCountry' => 'BR'];
        }
        if (($local['latitude'] ?? null) !== null && ($local['longitude'] ?? null) !== null) {
            $dados['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $local['latitude'], 'longitude' => $local['longitude']];
        }
        $telefone = preg_replace('/\D+/', '', (string) ($info['telefone'] ?? '')) ?? '';
        if (strlen($telefone) >= 10) {
            $dados['telephone'] = '+55' . $telefone;
        }
        return [$dados];
    }

    /**
     * Tudo que o head.php precisa, ja com valores padrao.
     * @param array<string,mixed> $seo da pagina: imagem, titulo (og:title sem a marca), tipo, jsonLd
     * @return array{url:string, canonical:?string, imagem:array{url:string,largura:int,altura:int,tipo:string,alt:string}, titulo:?string, tipo:string, jsonLd:list<string>, verificacao:string}
     */
    public static function meta(array $seo, bool $noIndex): array
    {
        $url = self::urlAtual();
        try {
            $imagem = is_array($seo['imagem'] ?? null) ? $seo['imagem'] : self::imagemPlataforma();
        } catch (\Throwable $erro) {
            error_log('[Cardapim] Imagem de compartilhamento padrao: ' . get_class($erro));
            $imagem = ['url' => self::absoluta('/assets/img/pwa/icon-512.png'), 'largura' => 512, 'altura' => 512, 'tipo' => 'image/png', 'alt' => marca()];
        }
        $jsonLd = [];
        foreach (is_array($seo['jsonLd'] ?? null) ? $seo['jsonLd'] : [] as $item) {
            if (is_array($item)) {
                $jsonLd[] = self::jsonLd($item);
            }
        }
        return [
            'url' => $url,
            'canonical' => $noIndex || self::base() === '' ? null : $url,
            'imagem' => $imagem,
            'titulo' => is_string($seo['titulo'] ?? null) ? $seo['titulo'] : null,
            'tipo' => is_string($seo['tipo'] ?? null) ? $seo['tipo'] : 'website',
            'jsonLd' => $jsonLd,
            'verificacao' => $noIndex ? '' : self::verificacaoGoogle(),
        ];
    }

    private static function texto(string $chave, string $padrao, int $max): string
    {
        $v = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', Configuracoes::textoOuPadrao($chave, $padrao)));
        return $v === '' ? $padrao : mb_substr($v, 0, $max);
    }
}
