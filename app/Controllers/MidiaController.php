<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Response;
use Cardapim\Modules\Marca\MarcaUpload;
use Cardapim\Modules\Restaurante\LogoUpload;

/**
 * Serve as logos enviadas pelos restaurantes e as da plataforma (guardadas em storage/, fora da pasta publica).
 * O nome do arquivo e aleatorio e validado por regex: nao ha como pedir outro arquivo do servidor.
 */
final class MidiaController
{
    private const TIPOS = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];

    public function logo(string $arquivo): Response
    {
        return $this->servir(LogoUpload::arquivoDe(LogoUpload::PREFIXO_URL . $arquivo));
    }

    /** Logos e icones da plataforma enviados em Configuracoes (SVG tambem: a politica "sandbox" impede qualquer script). */
    public function marca(string $arquivo): Response
    {
        return $this->servir(MarcaUpload::arquivoDe(MarcaUpload::PREFIXO_URL . $arquivo));
    }

    private function servir(?string $caminho): Response
    {
        if ($caminho === null || !is_file($caminho)) {
            return new Response('Imagem não encontrada.', 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $extensao = pathinfo($caminho, PATHINFO_EXTENSION);
        return new Response((string) file_get_contents($caminho), 200, [
            'Content-Type' => self::TIPOS[$extensao] ?? 'application/octet-stream',
            // Nome muda a cada envio, entao o arquivo pode ficar em cache "para sempre".
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline',
        ]);
    }
}
