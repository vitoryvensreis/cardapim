<?php

declare(strict_types=1);

namespace Cardapim\Shared;

/**
 * Endereco publico das imagens enviadas (logos dos restaurantes e da plataforma), servidas pelo PHP em /midia/...
 *
 * No banco fica "/midia/marca/{nome}.svg" (nome do arquivo no disco). Na tela vai "/midia/marca/{nome}-svg", SEM
 * extensao: o Nginx do CloudPanel trata todo endereco terminado em .svg/.png/.webp como arquivo estatico e responde 404
 * antes de chegar ao PHP. Sem a extensao, o pedido segue para o index.php normalmente, com qualquer vhost.
 */
final class MidiaUrl
{
    private const PADRAO = '~\A(/midia/(?:logos|marca)/[a-f0-9]{32}(?:-(?:512|192|maskable|apple))?)[.-](svg|png|jpg|webp)\z~';

    /** "/midia/marca/abc.svg" -> "/midia/marca/abc-svg". Outros enderecos voltam como vieram. */
    public static function publica(string $caminho): string
    {
        return preg_match(self::PADRAO, $caminho, $m) === 1 ? $m[1] . '-' . $m[2] : $caminho;
    }

    /** "/midia/marca/abc-svg" (ou o formato antigo "abc.svg") -> "/midia/marca/abc.svg". */
    public static function armazenada(string $caminho): string
    {
        return preg_match(self::PADRAO, $caminho, $m) === 1 ? $m[1] . '.' . $m[2] : $caminho;
    }
}
