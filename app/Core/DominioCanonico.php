<?php

declare(strict_types=1);

namespace Cardapim\Core;

/**
 * Troca de dominio sem perder ninguem: quem abrir um endereco antigo (DOMINIOS_REDIRECIONAR no .env, separados
 * por virgula) vai com 301 para o mesmo caminho no APP_URL.
 * - So GET e HEAD: formulario enviado de uma pagina antiga continua funcionando.
 * - /webhooks/ nunca e redirecionado: o Asaas segue avisando no endereco antigo ate o webhook ser configurado de novo.
 * - Lista vazia (padrao) = nada muda.
 */
final class DominioCanonico
{
    public static function redirecionamento(string $host, string $metodo, string $uri, string $appUrl, string $antigos): ?Response
    {
        $host = strtolower((string) preg_replace('/:\d+\z/', '', trim($host)));
        $lista = array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', $antigos)));
        if ($host === '' || !in_array($host, $lista, true) || !in_array(strtoupper($metodo), ['GET', 'HEAD'], true)) {
            return null;
        }
        $destino = parse_url($appUrl);
        if (!is_array($destino) || ($destino['scheme'] ?? '') !== 'https' || empty($destino['host']) || strtolower($destino['host']) === $host) {
            return null;
        }
        $caminho = str_starts_with($uri, '/') && !str_starts_with($uri, '//') ? $uri : '/';
        if (str_starts_with($caminho, '/webhooks/')) {
            return null;
        }
        // Destino vem do APP_URL (configuracao), nunca da requisicao: nao vira redirecionamento aberto.
        return new Response('', 301, [
            'Location' => 'https://' . strtolower($destino['host']) . $caminho,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public static function daRequisicao(): ?Response
    {
        return self::redirecionamento(
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            Config::get('APP_URL'),
            Config::get('DOMINIOS_REDIRECIONAR'),
        );
    }
}
