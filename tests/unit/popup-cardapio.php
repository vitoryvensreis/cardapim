<?php

declare(strict_types=1);

// Popup do cardapio (sem banco): validacao, quando aparece, endereco publico e recorte 1:1 da imagem.
// php tests/unit/popup-cardapio.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Restaurante\PopupUpload;
use Cardapim\Services\Gerencia\PopupService as P;
use Cardapim\Shared\MidiaUrl;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

// Validacao.
$v = P::validar(['ativo' => '1', 'tipo' => 'texto', 'titulo' => '  Promo  ', 'texto' => "Linha 1\r\nLinha 2"], false);
$ok($v['erros'] === [] && $v['dados']['titulo'] === 'Promo' && $v['dados']['texto'] === "Linha 1\nLinha 2", 'texto valido, limpo');
$ok(isset(P::validar(['ativo' => '1', 'tipo' => 'texto', 'titulo' => '', 'texto' => 'Oi'], false)['erros']['titulo']), 'texto ligado sem titulo recusado');
$ok(isset(P::validar(['ativo' => '1', 'tipo' => 'texto', 'titulo' => 'Oi', 'texto' => ''], false)['erros']['texto']), 'texto ligado sem mensagem recusado');
$ok(P::validar(['ativo' => '', 'tipo' => 'texto'], false)['erros'] === [], 'desligado salva mesmo vazio');
$ok(isset(P::validar(['ativo' => '1', 'tipo' => 'imagem'], false)['erros']['imagem']), 'imagem ligada sem imagem recusada');
$ok(P::validar(['ativo' => '1', 'tipo' => 'imagem'], true)['erros'] === [], 'imagem ligada com imagem ok');
$ok(isset(P::validar(['tipo' => 'texto', 'titulo' => str_repeat('a', 61)], false)['erros']['titulo']), 'titulo acima de 60 letras recusado');
$ok(isset(P::validar(['tipo' => 'texto', 'texto' => str_repeat('a', 301)], false)['erros']['texto']), 'mensagem acima de 300 letras recusada');
$ok(isset(P::validar(['inicio' => '2026-02-30'], false)['erros']['inicio']), 'data invalida recusada');
$ok(isset(P::validar(['inicio' => '2026-12-10', 'fim' => '2026-12-01'], false)['erros']['fim']), 'fim antes do inicio recusado');
$ok(P::validar(['tipo' => 'qualquer'], false)['dados']['tipo'] === 'imagem', 'tipo desconhecido vira imagem');

// Quando aparece.
$base = ['ativo' => true, 'tipo' => 'texto', 'titulo' => 'Oi', 'texto' => 'Mensagem', 'imagem_path' => null, 'inicio' => null, 'fim' => null];
$ok(P::visivel($base, '2026-11-26'), 'ligado e com texto: aparece');
$ok(!P::visivel(['ativo' => false] + $base, '2026-11-26'), 'desligado: nao aparece');
$ok(!P::visivel(['inicio' => '2026-11-27'] + $base, '2026-11-26'), 'antes do inicio: nao aparece');
$ok(P::visivel(['inicio' => '2026-11-26', 'fim' => '2026-11-26'] + $base, '2026-11-26'), 'datas inclusivas');
$ok(!P::visivel(['fim' => '2026-11-25'] + $base, '2026-11-26'), 'depois do fim: nao aparece');
$ok(!P::visivel(['tipo' => 'imagem'] + $base, '2026-11-26'), 'imagem sem arquivo: nao aparece');
$ok(!P::visivel(null, '2026-11-26'), 'sem configuracao: nao aparece');

// Endereco publico sem extensao (o Nginx trataria .webp como arquivo estatico).
$caminho = '/midia/popups/' . str_repeat('a', 32) . '.webp';
$ok(MidiaUrl::publica($caminho) === '/midia/popups/' . str_repeat('a', 32) . '-webp', 'endereco publico sem extensao');
$ok(PopupUpload::arquivoDe(MidiaUrl::publica($caminho)) === PopupUpload::pasta() . '/' . str_repeat('a', 32) . '.webp', 'endereco publico volta ao arquivo');
$ok(PopupUpload::arquivoDe('/midia/logos/' . str_repeat('a', 32) . '.webp') === null && PopupUpload::arquivoDe('/midia/popups/../x.webp') === null, 'so arquivos do popup');

// Recorte 1:1 e limite de 1080.
if (function_exists('imagecreatetruecolor')) {
    $tmp = tempnam(sys_get_temp_dir(), 'pop') . '.png';
    $i = imagecreatetruecolor(1600, 1000);
    imagepng($i, $tmp);
    $r = PopupUpload::quadrada($tmp, 'image/png', 1600, 1000);
    $s = $r !== null ? getimagesizefromstring($r[0]) : false;
    $ok($s !== false && $s[0] === 1000 && $s[1] === 1000, 'retangulo vira quadrado (1000 x 1000)');
    $i = imagecreatetruecolor(2400, 2400);
    imagepng($i, $tmp);
    $r = PopupUpload::quadrada($tmp, 'image/png', 2400, 2400);
    $s = $r !== null ? getimagesizefromstring($r[0]) : false;
    $ok($s !== false && $s[0] === 1080 && $s[1] === 1080, 'imagem grande reduzida para 1080 x 1080');
    $i = imagecreatetruecolor(120, 120);
    imagepng($i, $tmp);
    $erro = '';
    try {
        PopupUpload::salvar(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp]);
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
    $ok(str_contains($erro, 'pequena demais'), 'imagem muito pequena recusada');
    @unlink($tmp);
}

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Popup do cardapio: tudo certo.\n";
