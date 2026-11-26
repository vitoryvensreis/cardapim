<?php

declare(strict_types=1);

// Aparencia do cupom (AparenciaCupom + CupomImagem): validacao, arquivos de fonte, padrao e efeito no desenho.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Impressao\AparenciaCupom;
use Cardapim\Services\Impressao\CupomImagem;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    echo ($cond ? '  ok  ' : '  FALHOU  ') . $nome . "\n";
    $falhas += $cond ? 0 : 1;
};

$ok(AparenciaCupom::PADRAO === ['fonte' => 'roboto-mono', 'peso_texto' => 400, 'peso_destaque' => 700, 'tamanho' => 'normal'], 'padrao: Roboto Mono 400/700, letra normal');
$todos = true;
foreach (AparenciaCupom::FONTES as $id => $f) {
    foreach (array_keys($f['pesos']) as $p) {
        $todos = $todos && is_file(AparenciaCupom::arquivo($id, $p));
    }
}
$ok($todos, 'arquivo de cada peso de cada fonte existe');
$ok(array_keys(AparenciaCupom::FONTES['roboto-mono']['pesos']) === [100, 200, 300, 400, 500, 600, 700], 'Roboto Mono com os 7 pesos');
$ok(isset(AparenciaCupom::FONTES['poppins']['pesos'][900]), 'Poppins como segunda opcao (ate 900)');
$ok(str_ends_with(AparenciaCupom::arquivo('roboto-mono', 900), 'RobotoMono-Bold.ttf'), 'peso que a fonte nao tem vai para o mais proximo');

$valida = AparenciaCupom::validar(['fonte' => 'poppins', 'peso_texto' => '500', 'peso_destaque' => '800', 'tamanho' => 'grande']);
$ok($valida === ['fonte' => 'poppins', 'peso_texto' => 500, 'peso_destaque' => 800, 'tamanho' => 'grande'], 'valida e converte numeros');
$ok(is_string(AparenciaCupom::validar(['fonte' => 'arial', 'peso_texto' => 400, 'peso_destaque' => 700, 'tamanho' => 'normal'])), 'fonte desconhecida recusada');
$ok(is_string(AparenciaCupom::validar(['fonte' => 'roboto-mono', 'peso_texto' => 400, 'peso_destaque' => 800, 'tamanho' => 'normal'])), 'peso que a fonte nao tem recusado');
$ok(is_string(AparenciaCupom::validar(['fonte' => 'roboto-mono', 'peso_texto' => 400, 'peso_destaque' => 700, 'tamanho' => 'gigante'])), 'tamanho desconhecido recusado');
$ok(is_string(AparenciaCupom::validar([])), 'vazio recusado');

$db = new PDO('sqlite::memory:');
$r = AparenciaCupom::doRestaurante($db, 1);
$ok($r['personalizada'] === false && $r['fonte'] === 'roboto-mono', 'sem a migracao: padrao do sistema');

$opc = AparenciaCupom::opcoes();
$ok(count($opc['fontes']) === 2 && $opc['fontes'][0]['pesos'][3]['rotulo'] === 'Regular (400)', 'opcoes da tela com rotulos');
$ok(array_column($opc['tamanhos'], 'id') === ['pequena', 'normal', 'grande', 'extra'], 'tamanhos pequena, normal, grande e extra grande');
$ok(is_array(AparenciaCupom::validar(['tamanho' => 'extra'] + AparenciaCupom::PADRAO)), 'extra grande aceito');

if (CupomImagem::disponivel()) {
    $b = AparenciaCupom::exemplo('Loja Teste');
    $alt = static fn (string $png): int => imagesy(imagecreatefromstring($png));
    $pequena = $alt(CupomImagem::png($b, 80, 4, true, ['tamanho' => 'pequena'] + AparenciaCupom::PADRAO));
    $normal = $alt(CupomImagem::png($b, 80, 4, true));
    $grande = $alt(CupomImagem::png($b, 80, 4, true, ['tamanho' => 'grande'] + AparenciaCupom::PADRAO));
    $extra = $alt(CupomImagem::png($b, 80, 4, true, ['tamanho' => 'extra'] + AparenciaCupom::PADRAO));
    $ok($pequena < $normal && $normal < $grande && $grande < $extra, 'letra maior deixa o cupom mais comprido');
    $roboto = CupomImagem::png($b, 80, 4, true);
    $poppins = CupomImagem::png($b, 80, 4, true, ['fonte' => 'poppins'] + AparenciaCupom::PADRAO);
    $ok($roboto !== $poppins, 'fonte muda o desenho');
    $ok(CupomImagem::png($b, 80, 4, true, ['fonte' => 'invalida']) === $roboto, 'aparencia invalida desenha no padrao');
    $fino = CupomImagem::png($b, 80, 4, true, ['peso_texto' => 100] + AparenciaCupom::PADRAO);
    $ok($fino !== $roboto, 'peso muda o desenho');
}

if ($falhas > 0) {
    echo "$falhas falha(s).\n";
    exit(1);
}
echo "Aparencia do cupom: tudo certo.\n";
