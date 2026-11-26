<?php
declare(strict_types=1);

namespace Cardapim\Shared;

use InvalidArgumentException;

/**
 * Gerador de QR Code (ISO/IEC 18004) sem dependências: modo byte, correção de erro nível M, versões 1 a 20
 * (até ~660 bytes, sobra para a URL da NFC-e). Devolve a matriz de módulos; desenhar fica com quem usa
 * (cupom em imagem, PNG ou SVG). Escolhe a máscara de menor penalidade, como manda a norma.
 */
final class QrCode
{
    /** Por versão, nível M: [códigos de correção por bloco, [qtd blocos, dados por bloco], ...]. */
    private const BLOCOS_M = [
        1 => [10, [1, 16]], 2 => [16, [1, 28]], 3 => [26, [1, 44]], 4 => [18, [2, 32]], 5 => [24, [2, 43]],
        6 => [16, [4, 27]], 7 => [18, [4, 31]], 8 => [22, [2, 38], [2, 39]], 9 => [22, [3, 36], [2, 37]],
        10 => [26, [4, 43], [1, 44]], 11 => [30, [1, 50], [4, 51]], 12 => [22, [6, 36], [2, 37]],
        13 => [22, [8, 37], [1, 38]], 14 => [24, [4, 40], [5, 41]], 15 => [24, [5, 41], [5, 42]],
        16 => [28, [7, 45], [3, 46]], 17 => [28, [10, 46], [1, 47]], 18 => [26, [9, 43], [4, 44]],
        19 => [26, [3, 44], [11, 45]], 20 => [26, [3, 41], [13, 42]],
    ];
    private const ALINHAMENTO = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
        9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /** @var list<list<bool>> */
    private array $m = [];
    /** @var list<list<bool>> */
    private array $funcao = [];
    private int $lado = 0;

    /** @var list<int> */
    private static array $exp = [];
    /** @var list<int> */
    private static array $log = [];

    /** @return list<list<bool>> matriz [linha][coluna], true = módulo escuro (sem zona de silêncio) */
    public static function matriz(string $dados): array
    {
        return (new self())->gerar($dados);
    }

    /** @return list<list<bool>> */
    private function gerar(string $dados): array
    {
        $n = strlen($dados);
        $versao = 0;
        foreach (self::BLOCOS_M as $v => $b) {
            $capacidade = self::dadosVersao($v) * 8 - 4 - ($v < 10 ? 8 : 16);
            if ($n * 8 <= $capacidade) { $versao = $v; break; }
        }
        if ($versao === 0) { throw new InvalidArgumentException('Conteúdo grande demais para o QR Code.'); }
        $this->lado = $versao * 4 + 17;
        $this->m = array_fill(0, $this->lado, array_fill(0, $this->lado, false));
        $this->funcao = $this->m;
        $this->padroes($versao);
        $this->posicionar(self::entrelacar($versao, self::codificar($dados, $versao)));

        $melhor = 0; $menor = PHP_INT_MAX; $base = $this->m;
        for ($mascara = 0; $mascara < 8; $mascara++) {
            $this->m = $base;
            $this->mascarar($mascara);
            $this->formato($mascara);
            $p = $this->penalidade();
            if ($p < $menor) { $menor = $p; $melhor = $mascara; }
        }
        $this->m = $base;
        $this->mascarar($melhor);
        $this->formato($melhor);
        return $this->m;
    }

    private static function dadosVersao(int $v): int
    {
        $t = 0;
        foreach (array_slice(self::BLOCOS_M[$v], 1) as [$qtd, $tam]) { $t += $qtd * $tam; }
        return $t;
    }

    /** @return list<int> */
    private static function codificar(string $dados, int $versao): array
    {
        $bits = [];
        $poe = static function (int $valor, int $tam) use (&$bits): void {
            for ($i = $tam - 1; $i >= 0; $i--) { $bits[] = ($valor >> $i) & 1; }
        };
        $poe(0b0100, 4);
        $poe(strlen($dados), $versao < 10 ? 8 : 16);
        foreach (str_split($dados) as $c) { $poe(ord($c), 8); }
        $total = self::dadosVersao($versao) * 8;
        $poe(0, min(4, $total - count($bits)));
        while (count($bits) % 8 !== 0) { $bits[] = 0; }
        $bytes = [];
        foreach (array_chunk($bits, 8) as $o) { $bytes[] = (int) bindec(implode('', $o)); }
        for ($i = 0; count($bytes) < $total / 8; $i++) { $bytes[] = $i % 2 === 0 ? 0xEC : 0x11; }
        return $bytes;
    }

    /** @param list<int> $dados @return list<int> */
    private static function entrelacar(int $versao, array $dados): array
    {
        $cfg = self::BLOCOS_M[$versao];
        $ec = $cfg[0];
        $gerador = self::gerador($ec);
        $blocos = []; $corr = []; $pos = 0;
        foreach (array_slice($cfg, 1) as [$qtd, $tam]) {
            for ($i = 0; $i < $qtd; $i++) {
                $b = array_slice($dados, $pos, $tam);
                $pos += $tam;
                $blocos[] = $b;
                $corr[] = self::resto($b, $gerador);
            }
        }
        $saida = [];
        $maior = max(array_map('count', $blocos));
        for ($i = 0; $i < $maior; $i++) {
            foreach ($blocos as $b) { if (isset($b[$i])) { $saida[] = $b[$i]; } }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($corr as $c) { $saida[] = $c[$i]; }
        }
        return $saida;
    }

    private static function tabelas(): void
    {
        if (self::$exp !== []) { return; }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) { $x ^= 0x11D; }
        }
    }

    private static function mult(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) { return 0; }
        self::tabelas();
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /** @return list<int> coeficientes do polinômio gerador, do maior grau (1) ao termo independente */
    private static function gerador(int $grau): array
    {
        self::tabelas();
        $g = [1];
        for ($i = 0; $i < $grau; $i++) {
            $novo = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $c) {
                $novo[$j] ^= $c;
                $novo[$j + 1] ^= self::mult($c, self::$exp[$i]);
            }
            $g = $novo;
        }
        return $g;
    }

    /** @param list<int> $dados @param list<int> $gerador @return list<int> */
    private static function resto(array $dados, array $gerador): array
    {
        $n = count($gerador) - 1;
        $r = array_fill(0, $n, 0);
        foreach ($dados as $b) {
            $fator = $b ^ $r[0];
            array_shift($r);
            $r[] = 0;
            for ($i = 0; $i < $n; $i++) { $r[$i] ^= self::mult($gerador[$i + 1], $fator); }
        }
        return $r;
    }

    private function poe(int $x, int $y, bool $escuro): void
    {
        $this->m[$y][$x] = $escuro;
        $this->funcao[$y][$x] = true;
    }

    private function padroes(int $versao): void
    {
        $l = $this->lado;
        for ($i = 0; $i < $l; $i++) { $this->poe(6, $i, $i % 2 === 0); $this->poe($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [$l - 4, 3], [3, $l - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx; $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $l || $y >= $l) { continue; }
                    $d = max(abs($dx), abs($dy));
                    $this->poe($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
        $pos = self::ALINHAMENTO[$versao];
        $ult = count($pos) - 1;
        foreach ($pos as $i => $a) {
            foreach ($pos as $j => $b) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $ult) || ($i === $ult && $j === 0)) { continue; }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) { $this->poe($a + $dx, $b + $dy, max(abs($dx), abs($dy)) !== 1); }
                }
            }
        }
        $this->formato(0); // reserva a área do formato
        if ($versao >= 7) {
            $r = $versao;
            for ($i = 0; $i < 12; $i++) { $r = ($r << 1) ^ (($r >> 11) * 0x1F25); }
            $bits = ($versao << 12) | $r;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $l - 11 + $i % 3; $b = intdiv($i, 3);
                $this->poe($a, $b, $bit);
                $this->poe($b, $a, $bit);
            }
        }
    }

    private function formato(int $mascara): void
    {
        $dados = (0 << 3) | $mascara; // nível M = 00
        $r = $dados;
        for ($i = 0; $i < 10; $i++) { $r = ($r << 1) ^ (($r >> 9) * 0x537); }
        $bits = (($dados << 10) | $r) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $l = $this->lado;
        for ($i = 0; $i <= 5; $i++) { $this->poe(8, $i, $bit($i)); }
        $this->poe(8, 7, $bit(6));
        $this->poe(8, 8, $bit(7));
        $this->poe(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) { $this->poe(14 - $i, 8, $bit($i)); }
        for ($i = 0; $i < 8; $i++) { $this->poe($l - 1 - $i, 8, $bit($i)); }
        for ($i = 8; $i < 15; $i++) { $this->poe(8, $l - 15 + $i, $bit($i)); }
        $this->poe(8, $l - 8, true);
    }

    /** @param list<int> $codigos */
    private function posicionar(array $codigos): void
    {
        $l = $this->lado;
        $total = count($codigos) * 8;
        $i = 0;
        for ($dir = $l - 1; $dir >= 1; $dir -= 2) {
            if ($dir === 6) { $dir = 5; }
            for ($v = 0; $v < $l; $v++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $dir - $j;
                    $subindo = (($dir + 1) & 2) === 0;
                    $y = $subindo ? $l - 1 - $v : $v;
                    if (!$this->funcao[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($codigos[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function mascarar(int $k): void
    {
        for ($y = 0; $y < $this->lado; $y++) {
            for ($x = 0; $x < $this->lado; $x++) {
                if ($this->funcao[$y][$x]) { continue; }
                $inv = match ($k) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($inv) { $this->m[$y][$x] = !$this->m[$y][$x]; }
            }
        }
    }

    private function penalidade(): int
    {
        $l = $this->lado; $p = 0; $escuros = 0;
        $linhas = [];
        for ($y = 0; $y < $l; $y++) {
            $linhas[] = $this->m[$y];
            $col = [];
            for ($x = 0; $x < $l; $x++) {
                $col[] = $this->m[$x][$y];
                if ($this->m[$y][$x]) { $escuros++; }
                if ($x < $l - 1 && $y < $l - 1) {
                    $c = $this->m[$y][$x];
                    if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) { $p += 3; }
                }
            }
            $linhas[] = $col;
        }
        foreach ($linhas as $seq) {
            $corrida = 1;
            for ($i = 1; $i <= $l; $i++) {
                if ($i < $l && $seq[$i] === $seq[$i - 1]) { $corrida++; continue; }
                if ($corrida >= 5) { $p += $corrida - 2; }
                $corrida = 1;
            }
            $s = implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $seq));
            $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        $k = (int) ceil(abs($escuros * 20 - $l * $l * 10) / ($l * $l)) - 1;
        return $p + max(0, $k) * 10;
    }
}
