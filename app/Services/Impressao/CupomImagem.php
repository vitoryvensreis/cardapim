<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

/**
 * Desenha o cupom (blocos do CupomImpressao) como IMAGEM preto e branco do tamanho exato do papel.
 * O Deliveryzim Agent 0.9.0+ so manda a imagem para a impressora e corta: layout, letras, acentos, logo
 * e espaco antes do corte mudam aqui, sem instalar nada nas lojas.
 *
 * - Largura em pontos das termicas de 203 dpi: 58 mm = 384, 80 mm = 576.
 * - Texto sem suavizacao (ponto preto ou branco), para sair nitido no papel termico.
 * - Fonte, pesos e tamanho da letra pela aparencia do restaurante (AparenciaCupom; padrao Roboto Mono).
 *   Caractere que a fonte nao tem (ex.: emoji) vira "?".
 * - Espaco antes do corte: linhas em branco no fim da propria imagem (mesma medida do "ESC d n" do Agent).
 */
final class CupomImagem
{
    public const PONTOS = [58 => 384, 80 => 576];
    public const MARGEM_PADRAO = 4;
    /** Altura de uma "linha" do espaco antes do corte (avanco padrao das termicas, 1/6 de polegada). */
    private const LINHA_CORTE = 34;
    private const ALTURA_MAXIMA = 20000;
    private const LADO = 6;

    /**
     * Estilos do desenho atual: arquivo da fonte, tamanho (pontos do GD, 96 dpi) e altura da linha em pontos da impressora.
     * @var array<string,array{fonte:string,tamanho:float,linha:int}>
     */
    private static array $estilos = [];

    /** GD com fontes TrueType disponivel neste servidor? Sem ele, o Agent monta o cupom como antes. */
    public static function disponivel(): bool
    {
        $p = AparenciaCupom::PADRAO;
        return function_exists('imagettftext') && function_exists('imagepng')
            && is_file(AparenciaCupom::arquivo($p['fonte'], $p['peso_texto'])) && is_file(AparenciaCupom::arquivo($p['fonte'], $p['peso_destaque']));
    }

    /** @param array<string,mixed>|null $aparencia */
    private static function preparar(?array $aparencia): void
    {
        $a = is_array($aparencia) ? AparenciaCupom::validar($aparencia) : null;
        $a = is_array($a) ? $a : AparenciaCupom::PADRAO;
        $base = AparenciaCupom::FONTES[$a['fonte']]['base'] * AparenciaCupom::TAMANHOS[$a['tamanho']][1];
        $estilo = static fn (int $peso, float $tamanho, float $altura): array => [
            'fonte' => AparenciaCupom::arquivo($a['fonte'], $peso), 'tamanho' => $tamanho,
            'linha' => (int) round($tamanho * 96 / 72 * $altura),
        ];
        self::$estilos = [
            'normal' => $estilo($a['peso_texto'], $base, 1.5),
            'forte' => $estilo($a['peso_destaque'], $base, 1.5),
            'detalhe' => $estilo($a['peso_texto'], $base * 0.9, 1.5),
            'titulo' => $estilo($a['peso_destaque'], $base * 1.75, 1.45),
        ];
    }

    /**
     * PNG do cupom.
     * @param list<array<string,mixed>> $blocos
     * @param array<string,mixed>|null $aparencia AparenciaCupom (null = padrao do Deliveryzim)
     */
    public static function png(array $blocos, int $papel = 80, int $margem = self::MARGEM_PADRAO, bool $logo = true, ?array $aparencia = null): string
    {
        self::preparar($aparencia);
        $largura = self::PONTOS[$papel] ?? self::PONTOS[80];
        $margem = $margem >= 0 && $margem <= 20 ? $margem : self::MARGEM_PADRAO;
        $util = $largura - 2 * self::LADO;

        $logoImg = $logo ? self::logo($largura) : null;
        // 1) Monta as linhas (texto ja quebrado na largura) e soma a altura.
        $ops = [];
        $altura = 4;
        if ($logoImg !== null) {
            $ops[] = ['logo', $altura];
            $altura += imagesy($logoImg) + 12;
        }
        $poe = static function (string $estilo, string $texto, string $alinha = 'esq', int $recuo = 0, string $direita = '') use (&$ops, &$altura): void {
            $ops[] = ['texto', $altura, $estilo, $texto, $alinha, $recuo, $direita];
            $altura += self::$estilos[$estilo]['linha'];
        };
        foreach ($blocos as $b) {
            if (!is_array($b)) {
                continue;
            }
            $tipo = (string) ($b['t'] ?? 'texto');
            $texto = self::limpar((string) ($b['texto'] ?? ''));
            switch ($tipo) {
                case 'titulo':
                    foreach (self::quebrar($texto, 'titulo', $util) as $l) $poe('titulo', $l, 'centro');
                    break;
                case 'centro':
                    foreach (self::quebrar($texto, 'normal', $util) as $l) $poe('normal', $l, 'centro');
                    break;
                case 'destaque':
                    foreach (self::quebrar($texto, 'forte', $util) as $l) $poe('forte', $l);
                    break;
                case 'linha':
                    $ops[] = ['linha', $altura + 10];
                    $altura += 22;
                    break;
                case 'valor':
                    self::par($poe, !empty($b['forte']) ? 'forte' : 'normal', self::limpar((string) ($b['rotulo'] ?? '')),
                        self::limpar((string) ($b['valor'] ?? '')), $util);
                    break;
                case 'item':
                    $nome = trim(self::limpar(trim((string) ($b['qtd'] ?? '')) . ' ' . (string) ($b['nome'] ?? '')));
                    self::par($poe, 'forte', $nome, self::limpar((string) ($b['valor'] ?? '')), $util);
                    foreach (is_array($b['detalhes'] ?? null) ? $b['detalhes'] : [] as $d) {
                        foreach (self::quebrar(self::limpar((string) $d), 'detalhe', $util - 18) as $l) $poe('detalhe', $l, 'esq', 18);
                    }
                    break;
                case 'qrcode':
                    // QR Code (NFC-e): matriz gerada aqui, centralizada, com zona de silencio de 4 modulos.
                    try {
                        $matriz = \Cardapim\Shared\QrCode::matriz((string) ($b['dados'] ?? ''));
                    } catch (\Throwable) {
                        foreach (self::quebrar($texto, 'detalhe', $util) as $l) $poe('detalhe', $l, 'centro');
                        break;
                    }
                    $modulos = count($matriz) + 8;
                    $escala = max(2, intdiv((int) min($util * 0.62, 340), $modulos));
                    $ops[] = ['qr', $altura + 6, $matriz, $escala];
                    $altura += $modulos * $escala + 12;
                    break;
                default:
                    foreach (self::quebrar($texto, 'normal', $util) as $l) $poe('normal', $l);
            }
        }
        $altura += 2 * self::$estilos['normal']['linha'] + $margem * self::LINHA_CORTE;
        $altura = min($altura, self::ALTURA_MAXIMA);

        // 2) Desenha (paleta de 2 cores; indice negativo = sem suavizacao).
        $img = imagecreate($largura, $altura);
        $branco = imagecolorallocate($img, 255, 255, 255);
        $preto = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $branco);
        foreach ($ops as $op) {
            if ($op[1] >= $altura) {
                break;
            }
            if ($op[0] === 'logo' && $logoImg !== null) {
                self::colarLogo($img, $logoImg, (int) (($largura - imagesx($logoImg)) / 2), $op[1], $preto);
            } elseif ($op[0] === 'qr') {
                [, $y, $matriz, $escala] = $op;
                $lado = count($matriz);
                $x0 = (int) (($largura - $lado * $escala) / 2);
                $y0 = $y + 4 * $escala;
                foreach ($matriz as $l => $linha) {
                    foreach ($linha as $c => $escuro) {
                        if ($escuro) {
                            imagefilledrectangle($img, $x0 + $c * $escala, $y0 + $l * $escala, $x0 + ($c + 1) * $escala - 1, $y0 + ($l + 1) * $escala - 1, $preto);
                        }
                    }
                }
            } elseif ($op[0] === 'linha') {
                for ($x = self::LADO; $x < $largura - self::LADO; $x += 10) {
                    imagefilledrectangle($img, $x, $op[1], min($x + 5, $largura - self::LADO), $op[1] + 1, $preto);
                }
            } elseif ($op[0] === 'texto') {
                [, $y, $estilo, $texto, $alinha, $recuo, $direita] = $op;
                $e = self::$estilos[$estilo];
                $base = $y + (int) round($e['linha'] * 0.78);
                $x = self::LADO + $recuo;
                if ($alinha === 'centro') {
                    $x = (int) (($largura - self::medir($texto, $estilo)) / 2);
                }
                if ($texto !== '') {
                    imagettftext($img, $e['tamanho'], 0, $x, $base, -$preto, $e['fonte'], $texto);
                }
                if ($direita !== '') {
                    $xd = $largura - self::LADO - self::medir($direita, $estilo);
                    imagettftext($img, $e['tamanho'], 0, $xd, $base, -$preto, $e['fonte'], $direita);
                }
            }
        }
        if ($logoImg !== null) {
            imagedestroy($logoImg);
        }
        ob_start();
        imagepng($img, null, 9);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Rotulo a esquerda e valor a direita na mesma linha (o rotulo quebra se nao couber). */
    private static function par(callable $poe, string $estilo, string $rotulo, string $valor, int $util): void
    {
        $espacoValor = $valor === '' ? 0 : self::medir($valor, $estilo) + 14;
        if ($util - $espacoValor < 80) {
            foreach (self::quebrar($rotulo, $estilo, $util) as $l) $poe($estilo, $l);
            $poe($estilo, '', 'esq', 0, $valor);
            return;
        }
        $partes = self::quebrar($rotulo, $estilo, $util - $espacoValor) ?: [''];
        $ultima = array_pop($partes);
        foreach ($partes as $l) $poe($estilo, $l);
        $poe($estilo, $ultima, 'esq', 0, $valor);
    }

    /**
     * Quebra por palavras na largura em pontos (palavra maior que a linha e cortada).
     * @return list<string>
     */
    public static function quebrar(string $texto, string $estilo, int $largura): array
    {
        $palavras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $linhas = [];
        $atual = '';
        foreach ($palavras as $p) {
            while (self::medir($p, $estilo) > $largura && mb_strlen($p) > 1) {
                if ($atual !== '') {
                    $linhas[] = $atual;
                    $atual = '';
                }
                $n = mb_strlen($p);
                while ($n > 1 && self::medir(mb_substr($p, 0, $n), $estilo) > $largura) {
                    $n--;
                }
                $linhas[] = mb_substr($p, 0, $n);
                $p = mb_substr($p, $n);
            }
            $teste = $atual === '' ? $p : $atual . ' ' . $p;
            if ($atual !== '' && self::medir($teste, $estilo) > $largura) {
                $linhas[] = $atual;
                $atual = $p;
            } else {
                $atual = $teste;
            }
        }
        if ($atual !== '') {
            $linhas[] = $atual;
        }
        return $linhas;
    }

    private static function medir(string $texto, string $estilo): int
    {
        if ($texto === '') {
            return 0;
        }
        if (self::$estilos === []) {
            self::preparar(null);
        }
        $e = self::$estilos[$estilo];
        $caixa = imagettfbbox($e['tamanho'], 0, $e['fonte'], $texto);
        return $caixa === false ? 0 : (int) (max($caixa[2], $caixa[4]) - min($caixa[0], $caixa[6]));
    }

    /** Uma linha so, sem caracteres de controle; o que a fonte nao desenha (emoji, simbolos raros) vira "?". */
    public static function limpar(string $texto): string
    {
        $texto = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto);
        return (string) preg_replace('/[^\x{0020}-\x{024F}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2022}\x{2026}\x{20AC}]/u', '?', $texto);
    }

    /** Logo oficial (fundo transparente): o que e logo vira ponto preto. */
    private static function logo(int $largura): ?\GdImage
    {
        $arquivo = dirname(__DIR__, 3) . '/resources/img/logo-cupom.png';
        $origem = is_file($arquivo) ? @imagecreatefrompng($arquivo) : false;
        if ($origem === false) {
            return null;
        }
        $max = (int) round($largura * 0.6);
        if (imagesx($origem) <= $max) {
            return $origem;
        }
        $alto = max(1, (int) round(imagesy($origem) * $max / imagesx($origem)));
        $menor = imagecreatetruecolor($max, $alto);
        imagealphablending($menor, false);
        imagesavealpha($menor, true);
        imagefill($menor, 0, 0, imagecolorallocatealpha($menor, 0, 0, 0, 127));
        imagecopyresampled($menor, $origem, 0, 0, 0, 0, $max, $alto, imagesx($origem), imagesy($origem));
        imagedestroy($origem);
        return $menor;
    }

    private static function colarLogo(\GdImage $destino, \GdImage $logo, int $x0, int $y0, int $preto): void
    {
        for ($y = 0, $h = imagesy($logo); $y < $h; $y++) {
            for ($x = 0, $w = imagesx($logo); $x < $w; $x++) {
                $cor = imagecolorsforindex($logo, imagecolorat($logo, $x, $y));
                if ($cor['alpha'] < 64) { // alpha do GD: 0 = opaco, 127 = transparente
                    imagesetpixel($destino, $x0 + $x, $y0 + $y, $preto);
                }
            }
        }
    }
}
