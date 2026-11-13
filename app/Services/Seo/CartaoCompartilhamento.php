<?php

declare(strict_types=1);

namespace Cardapim\Services\Seo;

/**
 * Desenha a imagem que aparece no link compartilhado (WhatsApp, Instagram, Facebook, X, Telegram): JPG 1200 x 630.
 *
 * - Plataforma: fundo vinho, icone do app, nome e frase de Configuracoes. Muda sozinho quando o adm_master troca
 *   o nome, a frase ou o icone do app (a nao ser que ele envie uma imagem pronta, ver Seo::imagemPlataforma).
 * - Restaurante: logo do restaurante (ou as iniciais), nome, "Cardapio online", bairro/cidade e o endereco do link.
 *
 * Desenha em dobro (2400 x 1260) e reduz: cantos arredondados e textos ficam lisos. Guarda o resultado em
 * storage/cache/cartoes com o nome tirado do conteudo, entao so desenha de novo quando algo muda.
 * Fonte Poppins (licenca OFL) em resources/fonts.
 */
final class CartaoCompartilhamento
{
    public const LARGURA = 1200;
    public const ALTURA = 630;
    /** Mudar este numero redesenha todos os cartoes (quando o desenho mudar). */
    private const DESENHO = 1;
    private const ESCALA = 2;

    private const VINHO = [101, 20, 36];
    private const LARANJA = [255, 88, 37];
    private const ESCURO = [31, 20, 22];
    private const CINZA = [107, 91, 94];

    /**
     * @param array{nome:string, frase:string, icone:?string, endereco:string} $d icone = arquivo PNG/JPG/WebP no disco
     */
    public static function versaoPlataforma(array $d): string
    {
        return self::chave('p', $d, $d['icone']);
    }

    /**
     * @param array{nome:string, local:string, logo:?string, endereco:string, marca:string} $d logo = arquivo no disco
     */
    public static function versaoRestaurante(array $d): string
    {
        return self::chave('r', $d, $d['logo']);
    }

    /** @param array{nome:string, frase:string, icone:?string, endereco:string} $d */
    public static function plataforma(array $d): ?string
    {
        if (!self::disponivel()) {
            return null;
        }
        return self::doCache(self::versaoPlataforma($d), static fn (): string => self::desenharPlataforma($d));
    }

    /** @param array{nome:string, local:string, logo:?string, endereco:string, marca:string} $d */
    public static function restaurante(array $d): ?string
    {
        if (!self::disponivel()) {
            return null;
        }
        return self::doCache(self::versaoRestaurante($d), static fn (): string => self::desenharRestaurante($d));
    }

    /** GD com FreeType e JPG instalados e a fonte no lugar. */
    public static function disponivel(): bool
    {
        return function_exists('imagettftext') && function_exists('imagejpeg') && is_file(self::fonte('Bold'));
    }

    /** @param array<string,mixed> $d */
    private static function chave(string $tipo, array $d, ?string $arquivo): string
    {
        $marcaArquivo = $arquivo !== null && is_file($arquivo) ? $arquivo . '@' . (string) @filemtime($arquivo) . '@' . (string) @filesize($arquivo) : '';
        return substr(hash('sha256', self::DESENHO . '|' . $tipo . '|' . json_encode($d) . '|' . $marcaArquivo), 0, 20);
    }

    /** @param callable():string $desenhar */
    private static function doCache(string $chave, callable $desenhar): string
    {
        $pasta = dirname(__DIR__, 3) . '/storage/cache/cartoes';
        $arquivo = $pasta . '/' . $chave . '.jpg';
        if (is_file($arquivo)) {
            $bytes = (string) @file_get_contents($arquivo);
            if ($bytes !== '') {
                return $bytes;
            }
        }
        $bytes = $desenhar();
        // Sem permissao de escrita o cartao ainda sai, so nao fica guardado.
        if ((is_dir($pasta) || @mkdir($pasta, 0750, true)) && @file_put_contents($arquivo . '.tmp', $bytes, LOCK_EX) !== false) {
            @rename($arquivo . '.tmp', $arquivo);
        }
        return $bytes;
    }

    /** @param array{nome:string, frase:string, icone:?string, endereco:string} $d */
    private static function desenharPlataforma(array $d): string
    {
        $s = self::ESCALA;
        $img = self::tela(self::VINHO);
        // Circulos suaves ao fundo dao profundidade sem disputar com o texto.
        imagefilledellipse($img, 1120 * $s, 640 * $s, 620 * $s, 620 * $s, self::cor($img, [117, 27, 45]));
        imagefilledellipse($img, 1180 * $s, -40 * $s, 260 * $s, 260 * $s, self::cor($img, [117, 27, 45]));

        // Icone enviado no painel sobre um quadrado branco. Sem icone enviado, o cartao fica so com o nome e a frase
        // (nunca usa os arquivos originais do repositorio, que podem ser de uma marca antiga).
        $x = 96;
        if ($d['icone'] !== null) {
            [$ix, $iy, $il] = [96, 195, 240];
            self::retanguloArredondado($img, $ix * $s, $iy * $s, $il * $s, $il * $s, 48 * $s, self::cor($img, [255, 255, 255]));
            if (self::colarImagem($img, $d['icone'], ($ix + 22) * $s, ($iy + 22) * $s, ($il - 44) * $s, ($il - 44) * $s)) {
                $x = 400;
            } else {
                // Arquivo ilegivel: apaga o quadro e segue so com o texto.
                imagefilledrectangle($img, $ix * $s, $iy * $s, ($ix + $il) * $s, ($iy + $il) * $s, self::cor($img, self::VINHO));
            }
        }
        $largura = 1200 - $x - 80;
        $nomeLinhas = self::quebrar($d['nome'], 'Bold', 84 * $s, $largura * $s, 1);
        $frase = self::quebrar($d['frase'], 'Medium', 36 * $s, $largura * $s, 3);
        $alturaBloco = 100 + 34 + count($frase) * 52;
        $y = (int) round((630 - $alturaBloco) / 2) + 80;
        self::escrever($img, $nomeLinhas[0] ?? '', 'Bold', 84 * $s, $x * $s, $y * $s, [255, 255, 255]);
        // Traco laranja sob o nome: o detalhe da marca.
        self::retanguloArredondado($img, $x * $s, ($y + 30) * $s, 72 * $s, 8 * $s, 4 * $s, self::cor($img, self::LARANJA));
        $y += 30 + 8 + 62;
        foreach ($frase as $linha) {
            self::escrever($img, $linha, 'Medium', 36 * $s, $x * $s, $y * $s, [255, 255, 255], 18);
            $y += 52;
        }
        if ($d['endereco'] !== '') {
            self::escrever($img, $d['endereco'], 'Medium', 26 * $s, $x * $s, 572 * $s, [255, 255, 255], 55);
        }
        return self::jpg($img);
    }

    /** @param array{nome:string, local:string, logo:?string, endereco:string, marca:string} $d */
    private static function desenharRestaurante(array $d): string
    {
        $s = self::ESCALA;
        $img = self::tela([255, 255, 255]);
        // Faixa vinho da marca com a logo do restaurante em destaque.
        imagefilledrectangle($img, 0, 0, 460 * $s - 1, 630 * $s - 1, self::cor($img, self::VINHO));
        imagefilledellipse($img, 40 * $s, 640 * $s, 360 * $s, 360 * $s, self::cor($img, [117, 27, 45]));
        imagefilledrectangle($img, 460 * $s, 0, 468 * $s - 1, 630 * $s - 1, self::cor($img, self::LARANJA));
        [$lx, $ly, $ll] = [80, 165, 300];
        self::retanguloArredondado($img, $lx * $s, $ly * $s, $ll * $s, $ll * $s, 44 * $s, self::cor($img, [255, 255, 255]));
        if (!self::colarImagem($img, $d['logo'], ($lx + 30) * $s, ($ly + 30) * $s, ($ll - 60) * $s, ($ll - 60) * $s)) {
            self::textoCentralizado($img, self::iniciais($d['nome']), ($lx + $ll / 2) * $s, ($ly + $ll / 2) * $s, 112 * $s, self::VINHO);
        }

        $x = 532;
        $largura = 1200 - $x - 70;
        $nome = self::quebrar($d['nome'], 'Bold', 62 * $s, $largura * $s, 2);
        $temLocal = $d['local'] !== '';
        $alturaBloco = count($nome) * 78 + 58 + ($temLocal ? 50 : 0);
        $y = (int) round((630 - $alturaBloco) / 2) + 56;
        foreach ($nome as $linha) {
            self::escrever($img, $linha, 'Bold', 62 * $s, $x * $s, $y * $s, self::ESCURO);
            $y += 78;
        }
        self::escrever($img, 'Cardápio online · Peça pelo celular', 'Medium', 30 * $s, $x * $s, ($y - 10) * $s, self::VINHO);
        if ($temLocal) {
            $local = self::quebrar($d['local'], 'Medium', 26 * $s, $largura * $s, 1)[0] ?? '';
            self::escrever($img, $local, 'Medium', 26 * $s, $x * $s, ($y + 40) * $s, self::CINZA);
        }
        if ($d['endereco'] !== '') {
            $endereco = self::quebrar($d['endereco'], 'Medium', 22 * $s, $largura * $s, 1)[0] ?? '';
            self::escrever($img, $endereco, 'Medium', 22 * $s, $x * $s, 572 * $s, [138, 123, 126]);
        }
        return self::jpg($img);
    }

    /** @param array{0:int,1:int,2:int} $fundo */
    private static function tela(array $fundo): \GdImage
    {
        $img = imagecreatetruecolor(self::LARGURA * self::ESCALA, self::ALTURA * self::ESCALA);
        imagealphablending($img, true);
        imagefilledrectangle($img, 0, 0, self::LARGURA * self::ESCALA - 1, self::ALTURA * self::ESCALA - 1, self::cor($img, $fundo));
        return $img;
    }

    /** @param array{0:int,1:int,2:int} $rgb $transparencia 0 (opaco) a 100 */
    private static function cor(\GdImage $img, array $rgb, int $transparencia = 0): int
    {
        return (int) imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * max(0, min(100, $transparencia)) / 100));
    }

    private static function retanguloArredondado(\GdImage $img, int $x, int $y, int $l, int $a, int $raio, int $cor): void
    {
        $raio = min($raio, intdiv($l, 2), intdiv($a, 2));
        imagefilledrectangle($img, $x + $raio, $y, $x + $l - $raio - 1, $y + $a - 1, $cor);
        imagefilledrectangle($img, $x, $y + $raio, $x + $l - 1, $y + $a - $raio - 1, $cor);
        foreach ([[$x + $raio, $y + $raio], [$x + $l - $raio - 1, $y + $raio], [$x + $raio, $y + $a - $raio - 1], [$x + $l - $raio - 1, $y + $a - $raio - 1]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $raio * 2, $raio * 2, $cor);
        }
    }

    /** Cola a imagem do disco centralizada dentro da caixa, sem distorcer. false se nao der para abrir. */
    private static function colarImagem(\GdImage $img, ?string $arquivo, int $x, int $y, int $l, int $a): bool
    {
        if ($arquivo === null || !is_file($arquivo) || filesize($arquivo) > 8 * 1024 * 1024) {
            return false;
        }
        $info = @getimagesize($arquivo);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000) {
            return false;
        }
        $origem = match ($info['mime'] ?? '') {
            'image/png' => @imagecreatefrompng($arquivo),
            'image/jpeg' => @imagecreatefromjpeg($arquivo),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($arquivo) : false,
            default => false,
        };
        if (!$origem instanceof \GdImage) {
            return false;
        }
        $escala = min($l / $info[0], $a / $info[1]);
        $nl = max(1, (int) round($info[0] * $escala));
        $na = max(1, (int) round($info[1] * $escala));
        imagealphablending($img, true);
        imagecopyresampled($img, $origem, $x + intdiv($l - $nl, 2), $y + intdiv($a - $na, 2), 0, 0, $nl, $na, $info[0], $info[1]);
        return true;
    }

    private static function fonte(string $peso): string
    {
        return dirname(__DIR__, 3) . '/resources/fonts/Poppins-' . $peso . '.ttf';
    }

    /** @param array{0:int,1:int,2:int} $rgb $y = linha de base */
    private static function escrever(\GdImage $img, string $texto, string $peso, int $tamanho, int $x, int $y, array $rgb, int $transparencia = 0): void
    {
        imagettftext($img, $tamanho * 0.75, 0, $x, $y, self::cor($img, $rgb, $transparencia), self::fonte($peso), $texto);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function textoCentralizado(\GdImage $img, string $texto, float $cx, float $cy, int $tamanho, array $rgb): void
    {
        $caixa = imagettfbbox($tamanho * 0.75, 0, self::fonte('Bold'), $texto);
        if ($caixa === false) {
            return;
        }
        $l = $caixa[2] - $caixa[0];
        $a = $caixa[1] - $caixa[7];
        self::escrever($img, $texto, 'Bold', $tamanho, (int) round($cx - $l / 2 - $caixa[0]), (int) round($cy + $a / 2 - $caixa[1]), $rgb);
    }

    private static function larguraTexto(string $texto, string $peso, int $tamanho): int
    {
        $caixa = imagettfbbox($tamanho * 0.75, 0, self::fonte($peso), $texto);
        return $caixa === false ? 0 : $caixa[2] - $caixa[0];
    }

    /**
     * Quebra o texto em ate $maxLinhas linhas que cabem em $largura; o que sobrar vira "...".
     * @return list<string>
     */
    private static function quebrar(string $texto, string $peso, int $tamanho, int $largura, int $maxLinhas): array
    {
        $linhas = [];
        $atual = '';
        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palavra) {
            $tentativa = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($atual === '' || self::larguraTexto($tentativa, $peso, $tamanho) <= $largura) {
                $atual = $tentativa;
                continue;
            }
            $linhas[] = $atual;
            $atual = $palavra;
        }
        if ($atual !== '') {
            $linhas[] = $atual;
        }
        if (count($linhas) > $maxLinhas) {
            $linhas = [...array_slice($linhas, 0, $maxLinhas - 1), implode(' ', array_slice($linhas, $maxLinhas - 1))];
        }
        // A ultima linha (ou uma palavra sozinha maior que a linha) ganha reticencias quando nao cabe.
        return array_map(fn (string $l): string => self::reticencias($l, $peso, $tamanho, $largura), $linhas);
    }

    private static function reticencias(string $texto, string $peso, int $tamanho, int $largura): string
    {
        if (self::larguraTexto($texto, $peso, $tamanho) <= $largura) {
            return $texto;
        }
        while ($texto !== '' && self::larguraTexto(rtrim($texto) . '…', $peso, $tamanho) > $largura) {
            $texto = mb_substr($texto, 0, -1);
        }
        return rtrim($texto, " ,.;:-·") . '…';
    }

    private static function iniciais(string $nome): string
    {
        return \Cardapim\Shared\RestauranteLogo::iniciais($nome);
    }

    private static function jpg(\GdImage $grande): string
    {
        $final = imagecreatetruecolor(self::LARGURA, self::ALTURA);
        imagecopyresampled($final, $grande, 0, 0, 0, 0, self::LARGURA, self::ALTURA, self::LARGURA * self::ESCALA, self::ALTURA * self::ESCALA);
        imageinterlace($final, true);
        ob_start();
        imagejpeg($final, null, 86);
        return (string) ob_get_clean();
    }
}
