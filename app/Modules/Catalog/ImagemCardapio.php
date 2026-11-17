<?php

declare(strict_types=1);

namespace Cardapim\Modules\Catalog;

use RuntimeException;

/**
 * Foto de produto e de complemento do cardapio, com as mesmas regras da logo (LogoUpload):
 * - confere erro de upload, tamanho (5 MB), tipo real (finfo) e se e uma imagem valida (getimagesize);
 * - aceita so PNG, JPG e WebP; regrava com GD (remove metadados), no maximo 1200 px;
 * - salva com nome aleatorio em storage/uploads/cardapio (fora da pasta publica), servida por /midia/cardapio/...
 * - jpeg(): copia em JPG para enviar ao iFood (que nao aceita WebP).
 */
final class ImagemCardapio
{
    public const TAMANHO_MAXIMO = 5 * 1024 * 1024;
    public const LADO_MAXIMO = 1200;
    public const PREFIXO_URL = '/midia/cardapio/';
    private const TIPOS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public static function pasta(): string
    {
        return dirname(__DIR__, 3) . '/storage/uploads/cardapio';
    }

    /**
     * @param array<string,mixed> $arquivo item de $_FILES
     * @return string caminho publico (/midia/logos/{nome})
     * @throws RuntimeException com mensagem amigavel
     */
    public static function salvar(array $arquivo): string
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Escolha uma imagem para enviar.');
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('A imagem é grande demais. Envie uma de até 5 MB.');
        }
        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        if ($erro !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) && !self::modoTeste($tmp)) {
            throw new RuntimeException('Não foi possível receber a imagem. Tente de novo.');
        }
        $tamanho = (int) filesize($tmp);
        if ($tamanho <= 0 || $tamanho > self::TAMANHO_MAXIMO) {
            throw new RuntimeException('A imagem é grande demais. Envie uma de até 5 MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = @getimagesize($tmp);
        if (!isset(self::TIPOS[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 300 || $info[1] < 275 || $info[0] > 8000 || $info[1] > 8000) {
            throw new RuntimeException('Envie uma imagem PNG, JPG ou WebP (a partir de 300 x 275 pixels).');
        }

        $pasta = self::pasta();
        if (!is_dir($pasta) && !mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new RuntimeException('Não foi possível salvar a imagem agora. Tente mais tarde.');
        }
        $nome = bin2hex(random_bytes(16));
        $regravada = self::regravar($tmp, $mime, (int) $info[0], (int) $info[1]);
        if ($regravada !== null) {
            [$bytes, $extensao] = $regravada;
        } else {
            // Sem GD: guarda o arquivo original (ja validado), com extensao pelo tipo real.
            $bytes = (string) file_get_contents($tmp);
            $extensao = self::TIPOS[$mime];
        }
        $destino = $pasta . '/' . $nome . '.' . $extensao;
        if (file_put_contents($destino, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar a imagem agora. Tente mais tarde.');
        }
        @chmod($destino, 0640);
        return self::PREFIXO_URL . $nome . '.' . $extensao;
    }

    /** Apaga o arquivo de uma foto enviada antes (so os que este modulo criou). */
    public static function apagar(?string $caminhoPublico): void
    {
        $arquivo = self::arquivoDe($caminhoPublico);
        if ($arquivo !== null && is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

    /** "/midia/cardapio/abc...webp" -> caminho no disco; null se nao for um arquivo deste modulo. */
    public static function arquivoDe(?string $caminhoPublico): ?string
    {
        $caminhoPublico = $caminhoPublico === null ? null : \Cardapim\Shared\MidiaUrl::armazenada($caminhoPublico);
        if ($caminhoPublico === null || preg_match('~\A' . preg_quote(self::PREFIXO_URL, '~') . '([a-f0-9]{32}\.(?:webp|png|jpg))\z~', $caminhoPublico, $m) !== 1) {
            return null;
        }
        return self::pasta() . '/' . $m[1];
    }

    /** Foto em JPG (bytes) para enviar ao iFood; null se o arquivo nao existir ou nao der para converter. */
    public static function jpeg(?string $caminhoPublico): ?string
    {
        $arquivo = self::arquivoDe($caminhoPublico);
        if ($arquivo === null || !is_file($arquivo) || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $origem = match (strtolower(pathinfo($arquivo, PATHINFO_EXTENSION))) {
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($arquivo) : false,
            'png' => @imagecreatefrompng($arquivo),
            'jpg' => @imagecreatefromjpeg($arquivo),
            default => false,
        };
        if ($origem === false) {
            return null;
        }
        $l = imagesx($origem);
        $a = imagesy($origem);
        $fundo = imagecreatetruecolor($l, $a);
        imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
        imagecopy($fundo, $origem, 0, 0, 0, 0, $l, $a);
        ob_start();
        $ok = imagejpeg($fundo, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($origem);
        imagedestroy($fundo);
        return $ok && $bytes !== '' ? $bytes : null;
    }

    /** @return array{0:string,1:string}|null bytes e extensao */
    private static function regravar(string $tmp, string $mime, int $largura, int $altura): ?array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $origem = match ($mime) {
            'image/png' => @imagecreatefrompng($tmp),
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => false,
        };
        if ($origem === false) {
            return null;
        }
        $escala = min(1, self::LADO_MAXIMO / max($largura, $altura));
        $nl = max(1, (int) round($largura * $escala));
        $na = max(1, (int) round($altura * $escala));
        $destino = imagecreatetruecolor($nl, $na);
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $nl, $na, $largura, $altura);
        ob_start();
        $webp = function_exists('imagewebp');
        $ok = $webp ? imagewebp($destino, null, 88) : imagepng($destino, null, 7);
        $bytes = (string) ob_get_clean();
        imagedestroy($origem);
        imagedestroy($destino);
        return $ok && $bytes !== '' ? [$bytes, $webp ? 'webp' : 'png'] : null;
    }

    /** Testes automatizados (CLI) nao passam por is_uploaded_file. */
    private static function modoTeste(string $tmp): bool
    {
        return PHP_SAPI === 'cli' && is_file($tmp);
    }
}
