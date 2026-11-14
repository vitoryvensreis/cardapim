<?php

declare(strict_types=1);

namespace Cardapim\Services\Geo;

/**
 * Mapa proprio: arquivo PMTiles (ruas, bairros, agua) servido pela nossa VPS em /mapas/.
 *
 * O desenho acontece no navegador (protomaps-leaflet); o Nginx so entrega pedacos do arquivo (HTTP Range),
 * sem PHP nem banco. Sem o arquivo, ou com o local fora da area dele, a tela usa o OpenStreetMap publico
 * (public/assets/js/mapa-base.js). O arquivo e gerado no CI (deploy/scripts/mapa-gerar.sh), guardado em
 * shared/mapas na VPS e ligado em public/mapas de cada release. Detalhes: docs/architecture/mapa.md.
 */
final class MapaBase
{
    /** Nome fixo usado por mapa-instalar.sh. Para cobrir mais estados, trocar o recorte (bbox) em mapa-gerar.sh. */
    public const ARQUIVO = 'ceara.pmtiles';

    /** Cabecalho do PMTiles v3: arquivo menor que isso nao e um mapa. */
    private const TAMANHO_MINIMO = 127;

    /**
     * URL publica do arquivo (com ?v=data para o celular pegar o novo depois de uma atualizacao),
     * ou null quando nao ha mapa proprio instalado.
     */
    public static function url(?string $pastaPublica = null): ?string
    {
        $arquivo = ($pastaPublica ?? dirname(__DIR__, 3) . '/public') . '/mapas/' . self::ARQUIVO;
        clearstatcache(true, $arquivo);
        if (!is_file($arquivo) || !is_readable($arquivo) || (int) @filesize($arquivo) < self::TAMANHO_MINIMO) {
            return null;
        }
        $data = @filemtime($arquivo);
        return '/mapas/' . self::ARQUIVO . ($data !== false ? '?v=' . base_convert((string) $data, 10, 36) : '');
    }
}
