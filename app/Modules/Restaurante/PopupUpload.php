<?php

declare(strict_types=1);

namespace Cardapim\Modules\Restaurante;

use RuntimeException;

/**
 * Banner do popup do cardapio, com as mesmas regras de seguranca da logo (LogoUpload):
 * - confere erro de upload, tamanho (5 MB), tipo real (finfo) e se e uma imagem valida (getimagesize);
 * - aceita so PNG, JPG e WebP (SVG nao: pode carregar script);
 * - regrava com GD (remove metadados): corta o centro em quadrado (1:1) e reduz para no maximo 1080 x 1080;
 * - salva com nome aleatorio em storage/uploads/popups (fora da pasta publica), servida por /midia/popups/...
 */
final class PopupUpload
{
    public const TAMANHO_MAXIMO = 5 * 1024 * 1024;
    public const LADO_MAXIMO = 1080;
    public const LADO_MINIMO = 200;
    public const PREFIXO_URL = '/midia/popups/';
    private const TIPOS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public static function pasta(): string
    {
        return dirname(__DIR__, 3) . '/storage/uploads/popups';
    }

    /**
     * @param array<string,mixed> $arquivo item de $_FILES
     * @return string caminho publico (/midia/popups/{nome})
     * @throws RuntimeException com mensagem amigavel
     */
    public static function salvar(array $arquivo): string
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Escolha uma imagem para o popup.');
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
        if (!isset(self::TIPOS[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime || $info[0] > 8000 || $info[1] > 8000) {
            throw new RuntimeException('Envie uma imagem PNG, JPG ou WebP.');
        }
        if (min($info[0], $info[1]) < self::LADO_MINIMO) {
            throw new RuntimeException('A imagem é pequena demais. Envie uma de pelo menos ' . self::LADO_MINIMO . ' x ' . self::LADO_MINIMO . ' pixels (o ideal é 1080 x 1080).');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Não foi possível preparar a imagem agora. Tente mais tarde.');
        }
        $regravada = self::quadrada($tmp, $mime, (int) $info[0], (int) $info[1]);
        if ($regravada === null) {
            throw new RuntimeException('Não foi possível ler esta imagem. Tente salvar em JPG ou PNG e envie de novo.');
        }
        [$bytes, $extensao] = $regravada;
        $pasta = self::pasta();
        if (!is_dir($pasta) && !mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new RuntimeException('Não foi possível salvar a imagem agora. Tente mais tarde.');
        }
        $nome = bin2hex(random_bytes(16));
        $destino = $pasta . '/' . $nome . '.' . $extensao;
        if (file_put_contents($destino, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar a imagem agora. Tente mais tarde.');
        }
        @chmod($destino, 0640);
        return self::PREFIXO_URL . $nome . '.' . $extensao;
    }

    /** Apaga o arquivo de um banner enviado antes (so os que este modulo criou). */
    public static function apagar(?string $caminhoPublico): void
    {
        $arquivo = self::arquivoDe($caminhoPublico);
        if ($arquivo !== null && is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

    /** "/midia/popups/abc...webp" -> caminho no disco; null se nao for um arquivo deste modulo. */
    public static function arquivoDe(?string $caminhoPublico): ?string
    {
        $caminhoPublico = $caminhoPublico === null ? null : \Cardapim\Shared\MidiaUrl::armazenada($caminhoPublico);
        if ($caminhoPublico === null || preg_match('~\A' . preg_quote(self::PREFIXO_URL, '~') . '([a-f0-9]{32}\.(?:webp|png|jpg))\z~', $caminhoPublico, $m) !== 1) {
            return null;
        }
        return self::pasta() . '/' . $m[1];
    }

    /**
     * Corta o centro em quadrado e reduz para no maximo LADO_MAXIMO.
     * @return array{0:string,1:string}|null bytes e extensao
     */
    public static function quadrada(string $arquivo, string $mime, int $largura, int $altura): ?array
    {
        $origem = match ($mime) {
            'image/png' => @imagecreatefrompng($arquivo),
            'image/jpeg' => @imagecreatefromjpeg($arquivo),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($arquivo) : false,
            default => false,
        };
        if ($origem === false) {
            return null;
        }
        $lado = min($largura, $altura);
        $x = intdiv($largura - $lado, 2);
        $y = intdiv($altura - $lado, 2);
        $final = min($lado, self::LADO_MAXIMO);
        $destino = imagecreatetruecolor($final, $final);
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        imagecopyresampled($destino, $origem, 0, 0, $x, $y, $final, $final, $lado, $lado);
        ob_start();
        $webp = function_exists('imagewebp');
        $ok = $webp ? imagewebp($destino, null, 86) : imagepng($destino, null, 7);
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
