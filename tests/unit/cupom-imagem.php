<?php

declare(strict_types=1);

// Cupom em imagem (CupomImagem): largura do papel, espaco antes do corte, quebra de linha e caracteres sem fonte.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Impressao\CupomImagem;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

if (!CupomImagem::disponivel()) {
    echo "GD com FreeType indisponivel: teste ignorado.\n";
    exit(0);
}
$blocos = [
    ['t' => 'titulo', 'texto' => 'MESA 18'], ['t' => 'linha'],
    ['t' => 'item', 'qtd' => '2x', 'nome' => 'Espetinho de frango com bacon e queijo coalho', 'valor' => 'R$ 24,00', 'detalhes' => ['+ Farofa (2x)']],
    ['t' => 'valor', 'rotulo' => 'Total', 'valor' => 'R$ 24,00', 'forte' => true],
    ['t' => 'texto', 'texto' => 'Ação ç é 🍕'], ['t' => 'desconhecido', 'texto' => 'nao some'],
];
$medidas = static function (string $png): array {
    $i = imagecreatefromstring($png);
    return [imagesx($i), imagesy($i)];
};
$p80 = CupomImagem::png($blocos, 80, 4, true);
$p58 = CupomImagem::png($blocos, 58, 4, true);
$ok(str_starts_with($p80, "\x89PNG"), 'gera PNG');
$ok($medidas($p80)[0] === 576, 'papel 80 mm = 576 pontos');
$ok($medidas($p58)[0] === 384, 'papel 58 mm = 384 pontos');
$ok($medidas($p58)[1] > $medidas($p80)[1], 'papel menor quebra mais linhas (cupom mais comprido)');
$ok($medidas(CupomImagem::png($blocos, 80, 10, true))[1] - $medidas($p80)[1] === 6 * 34, 'espaco antes do corte aumenta o fim da imagem');
$ok($medidas(CupomImagem::png($blocos, 80, 4, false))[1] < $medidas($p80)[1], 'sem logo fica mais curto');
$ok($medidas(CupomImagem::png($blocos, 70, 99, true))[0] === 576, 'papel ou margem invalidos usam o padrao');
$ok(CupomImagem::limpar("Pizza 🍕\tGrande") === 'Pizza ? Grande', 'emoji vira ? e tab vira espaco');
$ok(CupomImagem::limpar('Ação – “ok” · R$ 5,00 €') === 'Ação – “ok” · R$ 5,00 €', 'acentos e simbolos comuns ficam');
$linhas = CupomImagem::quebrar(str_repeat('palavra ', 30), 'normal', 300);
$ok(count($linhas) > 3 && max(array_map('mb_strlen', $linhas)) < 40, 'quebra por palavras na largura');
$ok(CupomImagem::quebrar('', 'normal', 300) === [], 'texto vazio nao gera linha');
$img = imagecreatefromstring($p80);
$preto = 0;
for ($x = 0; $x < 576; $x += 3) {
    for ($y = 0; $y < imagesy($img); $y += 3) {
        $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
        $preto += $c['red'] === 0 ? 1 : 0;
        if ($c['red'] !== 0 && $c['red'] !== 255) {
            $ok(false, 'so preto e branco (sem cinza)');
            exit(1);
        }
    }
}
$ok($preto > 200, 'tem texto desenhado e so preto e branco');

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Cupom em imagem: tudo certo.\n";
