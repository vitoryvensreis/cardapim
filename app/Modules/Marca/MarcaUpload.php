<?php

declare(strict_types=1);

namespace Cardapim\Modules\Marca;

use RuntimeException;

/**
 * Logos e icones da PLATAFORMA enviados pelo adm_master (Configuracoes > Logos).
 * - Logos e favicon: SVG (conferido: sem script, eventos, links externos ou entidades) ou PNG/JPG/WebP (regravado com GD).
 * - Icone do app (PWA): PNG/JPG/WebP quadrado de 512 px ou mais; gera 512, 192, versao "maskable" e a do iPhone (180).
 * Arquivos com nome aleatorio em storage/uploads/marca (fora da pasta publica), servidos por /midia/marca/...
 * com Content-Security-Policy "sandbox" (nada roda nem se o arquivo for aberto direto).
 */
final class MarcaUpload
{
    public const TAMANHO_MAXIMO = 2 * 1024 * 1024;
    public const PREFIXO_URL = '/midia/marca/';
    private const LADO_LOGO = 1200;
    private const RASTER = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public static function pasta(): string
    {
        return dirname(__DIR__, 3) . '/storage/uploads/marca';
    }

    /**
     * @param array<string,mixed> $arquivo item de $_FILES
     * @return string caminho publico (/midia/marca/{nome})
     * @throws RuntimeException com mensagem amigavel
     */
    public static function salvar(array $arquivo, bool $iconeApp): string
    {
        $tmp = self::receber($arquivo);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $nome = bin2hex(random_bytes(16));
        self::garantirPasta();

        if (!$iconeApp && in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'], true)) {
            $svg = (string) file_get_contents($tmp);
            self::conferirSvg($svg);
            self::gravar($nome . '.svg', $svg);
            return self::PREFIXO_URL . $nome . '.svg';
        }
        $info = @getimagesize($tmp);
        if (!isset(self::RASTER[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 32 || $info[1] < 32 || $info[0] > 8000 || $info[1] > 8000) {
            throw new RuntimeException($iconeApp
                ? 'Envie o ícone do app em PNG, JPG ou WebP, quadrado, com 512 x 512 pixels ou mais.'
                : 'Envie a logo em SVG, PNG, JPG ou WebP (a partir de 32 pixels).');
        }
        [$largura, $altura] = [(int) $info[0], (int) $info[1]];
        if ($iconeApp) {
            if ($largura < 512 || $altura < 512 || abs($largura - $altura) > max($largura, $altura) * 0.05) {
                throw new RuntimeException('O ícone do app precisa ser quadrado e ter 512 x 512 pixels ou mais.');
            }
            $origem = self::abrir($tmp, $mime);
            if ($origem === null) {
                // Sem GD: guarda o PNG original como unico tamanho.
                if ($mime !== 'image/png') {
                    throw new RuntimeException('Envie o ícone do app em PNG.');
                }
                self::gravar($nome . '-512.png', (string) file_get_contents($tmp));
                return self::PREFIXO_URL . $nome . '-512.png';
            }
            self::gravar($nome . '-512.png', self::png($origem, $largura, $altura, 512, 1.0, null));
            self::gravar($nome . '-192.png', self::png($origem, $largura, $altura, 192, 1.0, null));
            self::gravar($nome . '-maskable.png', self::png($origem, $largura, $altura, 512, 0.8, [255, 255, 255]));
            self::gravar($nome . '-apple.png', self::png($origem, $largura, $altura, 180, 0.9, [255, 255, 255]));
            return self::PREFIXO_URL . $nome . '-512.png';
        }
        $origem = self::abrir($tmp, $mime);
        if ($origem === null) {
            $ext = self::RASTER[$mime];
            self::gravar($nome . '.' . $ext, (string) file_get_contents($tmp));
            return self::PREFIXO_URL . $nome . '.' . $ext;
        }
        $escala = min(1, self::LADO_LOGO / max($largura, $altura));
        $nl = max(1, (int) round($largura * $escala));
        $na = max(1, (int) round($altura * $escala));
        $destino = self::tela($nl, $na, null);
        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $nl, $na, $largura, $altura);
        self::gravar($nome . '.png', self::bytesPng($destino));
        return self::PREFIXO_URL . $nome . '.png';
    }

    /** "/midia/marca/abc.svg" -> caminho no disco; null se nao for um arquivo deste modulo. */
    public static function arquivoDe(?string $caminhoPublico): ?string
    {
        $caminhoPublico = $caminhoPublico === null ? null : \Cardapim\Shared\MidiaUrl::armazenada($caminhoPublico);
        if ($caminhoPublico === null || preg_match('~\A' . preg_quote(self::PREFIXO_URL, '~') . '([a-f0-9]{32}(?:-(?:512|192|maskable|apple))?\.(?:svg|png|jpg|webp))\z~', $caminhoPublico, $m) !== 1) {
            return null;
        }
        return self::pasta() . '/' . $m[1];
    }

    /** Tamanhos do icone do app gerados junto com o de 512. @return array{192:string, maskable:string, apple:string}|null */
    public static function variantesIcone(string $caminho512): ?array
    {
        $caminho512 = \Cardapim\Shared\MidiaUrl::armazenada($caminho512);
        if (preg_match('~\A(' . preg_quote(self::PREFIXO_URL, '~') . '[a-f0-9]{32})-512\.png\z~', $caminho512, $m) !== 1) {
            return null;
        }
        $v = ['192' => $m[1] . '-192.png', 'maskable' => $m[1] . '-maskable.png', 'apple' => $m[1] . '-apple.png'];
        foreach ($v as $caminho) {
            $disco = self::arquivoDe($caminho);
            if ($disco === null || !is_file($disco)) {
                return null;
            }
        }
        // Enderecos para a tela (sem extensao: ver Shared\MidiaUrl).
        return array_map([\Cardapim\Shared\MidiaUrl::class, 'publica'], ['192' => $v['192'], 'maskable' => $v['maskable'], 'apple' => $v['apple']]);
    }

    /**
     * SVG seguro: XML valido com raiz <svg>, sem script, objetos embutidos, eventos (on...), javascript:, links
     * externos, folhas de estilo externas nem entidades (XXE).
     * @throws RuntimeException
     */
    public static function conferirSvg(string $svg): void
    {
        $invalido = new RuntimeException('Este SVG não pode ser usado (tem script, links externos ou não é um SVG válido). Exporte de novo ou envie em PNG.');
        if ($svg === '' || strlen($svg) > self::TAMANHO_MAXIMO || preg_match('/<svg[\s>]/i', $svg) !== 1) {
            throw $invalido;
        }
        $proibido = '/<!ENTITY|<!DOCTYPE|<script|<foreignObject|<iframe|<embed|<object|<use[^>]+href\s*=\s*["\']\s*(?:https?:|\/\/|data:)|\son[a-z]+\s*=|javascript:|vbscript:|@import|(?:xlink:)?href\s*=\s*["\']\s*(?:https?:|\/\/)/i';
        if (preg_match($proibido, $svg) === 1) {
            throw $invalido;
        }
        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $anterior = libxml_use_internal_errors(true);
            $ok = $dom->loadXML($svg, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
            if (!$ok || $dom->documentElement === null || strtolower($dom->documentElement->localName) !== 'svg') {
                throw $invalido;
            }
        }
    }

    /** @param array<string,mixed> $arquivo */
    private static function receber(array $arquivo): string
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Escolha um arquivo para enviar.');
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('O arquivo é grande demais. Envie um de até 2 MB.');
        }
        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        if ($erro !== UPLOAD_ERR_OK || $tmp === '' || (!is_uploaded_file($tmp) && !(PHP_SAPI === 'cli' && is_file($tmp)))) {
            throw new RuntimeException('Não foi possível receber o arquivo. Tente de novo.');
        }
        $tamanho = (int) filesize($tmp);
        if ($tamanho <= 0 || $tamanho > self::TAMANHO_MAXIMO) {
            throw new RuntimeException('O arquivo é grande demais. Envie um de até 2 MB.');
        }
        return $tmp;
    }

    private static function garantirPasta(): void
    {
        $pasta = self::pasta();
        if (!is_dir($pasta) && !mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new RuntimeException('Não foi possível salvar o arquivo agora. Tente mais tarde.');
        }
    }

    private static function gravar(string $nome, string $bytes): void
    {
        $destino = self::pasta() . '/' . $nome;
        if ($bytes === '' || file_put_contents($destino, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar o arquivo agora. Tente mais tarde.');
        }
        @chmod($destino, 0640);
    }

    private static function abrir(string $tmp, string $mime): ?\GdImage
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $img = match ($mime) {
            'image/png' => @imagecreatefrompng($tmp),
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }

    /** Quadrado de $lado px com a imagem ocupando $ocupa (0..1) do lado, centralizada; $fundo null = transparente. */
    private static function png(\GdImage $origem, int $largura, int $altura, int $lado, float $ocupa, ?array $fundo): string
    {
        $destino = self::tela($lado, $lado, $fundo);
        $interno = (int) round($lado * $ocupa);
        $deslocamento = (int) round(($lado - $interno) / 2);
        imagealphablending($destino, true);
        imagecopyresampled($destino, $origem, $deslocamento, $deslocamento, 0, 0, $interno, $interno, $largura, $altura);
        return self::bytesPng($destino);
    }

    private static function tela(int $l, int $a, ?array $fundo): \GdImage
    {
        $img = imagecreatetruecolor($l, $a);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $cor = $fundo === null ? imagecolorallocatealpha($img, 0, 0, 0, 127) : imagecolorallocate($img, $fundo[0], $fundo[1], $fundo[2]);
        imagefilledrectangle($img, 0, 0, $l - 1, $a - 1, $cor);
        return $img;
    }

    private static function bytesPng(\GdImage $img): string
    {
        imagesavealpha($img, true);
        ob_start();
        imagepng($img, null, 7);
        return (string) ob_get_clean();
    }
}
