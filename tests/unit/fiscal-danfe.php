<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Fiscal\GestaoNotas;
use Cardapim\Services\Impressao\CupomImagem;
use Cardapim\Services\Impressao\DanfeNfce;
use Cardapim\Shared\QrCode;

function ok(bool $v, string $m): void { if (!$v) { throw new RuntimeException('DANFE/QR: ' . $m); } }

// ---------- QR Code: estrutura da norma ----------
$url = 'https://www.sefaz.ce.gov.br/nfce/consulta?p=23261111222333000181650010000000421123456785|2|2|1|ABCDEF0123456789ABCDEF0123456789ABCDEF01';
$m = QrCode::matriz($url);
$n = count($m);
ok($n === 49 && count($m[0]) === 49, 'versão 8 para a URL da NFC-e (' . $n . ')');
ok(QrCode::matriz('A') === QrCode::matriz('A') && count(QrCode::matriz('A')) === 21, 'determinístico e versão 1');
ok(count(QrCode::matriz(str_repeat('x', 600))) === 93, 'versão 19 para 600 bytes');
foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
    for ($i = 0; $i < 7; $i++) {
        ok($m[$y][$x + $i] && $m[$y + 6][$x + $i] && $m[$y + $i][$x] && $m[$y + $i][$x + 6], 'borda do localizador');
    }
    ok($m[$y + 3][$x + 3] && !$m[$y + 1][$x + 1], 'miolo do localizador');
}
for ($i = 8; $i < $n - 8; $i++) { ok($m[6][$i] === ($i % 2 === 0) && $m[$i][6] === ($i % 2 === 0), 'padrão de sincronismo'); }
ok($m[$n - 8][8] === true, 'módulo escuro fixo');
// As duas cópias do formato devem ser iguais.
$a = []; $b = [];
for ($i = 0; $i <= 5; $i++) { $a[] = $m[$i][8]; }
$a[] = $m[7][8]; $a[] = $m[8][8]; $a[] = $m[8][7];
for ($i = 9; $i < 15; $i++) { $a[] = $m[8][14 - $i]; }
for ($i = 0; $i < 8; $i++) { $b[] = $m[8][$n - 1 - $i]; }
for ($i = 8; $i < 15; $i++) { $b[] = $m[$n - 15 + $i][8]; }
ok($a === $b, 'cópias do formato');
try { QrCode::matriz(str_repeat('x', 800)); ok(false, 'conteúdo grande aceito'); } catch (InvalidArgumentException) {}

// ---------- DANFE ----------
$doc = [
    'id' => 9, 'id_pedido' => 3, 'numero' => 42, 'serie' => 1, 'ambiente' => 2, 'chave' => '23261111222333000181650010000000421123456785',
    'protocolo' => '123260000000001', 'emitida_em' => '2026-11-27 12:00:00', 'autorizada_em' => '2026-11-27 12:00:02', 'cpf_cnpj' => '52998224725',
    'qrcode' => $url, 'url_consulta' => 'https://www.sefaz.ce.gov.br/nfce/consulta', 'restaurante' => 'Lanche',
    'dados' => json_encode([
        'cnpj' => '11222333000181', 'empresa' => ['razao' => 'Lanchonete LTDA', 'ie' => '123', 'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cidade' => 'Fortaleza', 'estado' => 'CE'],
        'pedido' => ['numero' => 77],
        'itens' => [['codigo' => '10', 'nome' => 'X-Burguer', 'qtd' => 2000, 'vprod' => 5000, 'vdesc' => 500, 'voutro' => 300, 'ucom' => 'UN']],
        'pagamentos' => [['tpag' => '01', 'vpag' => 5000, 'troco' => 200]],
    ]),
];
$b = DanfeNfce::blocos($doc);
$texto = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ok(str_contains($texto, '2326 1111 2223 3300 0181 6500 1000 0000 4211 2345 6785'), 'chave em grupos de 4');
ok(str_contains($texto, 'SEM VALOR FISCAL') && str_contains($texto, 'CNPJ 11.222.333/0001-81'), 'aviso de homologação e CNPJ');
ok(str_contains($texto, 'CPF 529.982.247-25') && str_contains($texto, 'Série 001') && str_contains($texto, '000000042'), 'consumidor e identificação');
ok(str_contains($texto, 'R$ 48,00') && str_contains($texto, 'Troco') && str_contains($texto, 'unitário 25,00'), 'totais e troco');
$qr = array_values(array_filter($b, static fn ($x) => ($x['t'] ?? '') === 'qrcode'));
ok(count($qr) === 1 && $qr[0]['dados'] === $url, 'bloco do QR Code');
$doc['ambiente'] = 1; $doc['cpf_cnpj'] = null; $doc['qrcode'] = null;
$texto = json_encode(DanfeNfce::blocos($doc), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ok(!str_contains($texto, 'SEM VALOR FISCAL') && str_contains($texto, 'CONSUMIDOR NÃO IDENTIFICADO') && str_contains($texto, 'QR Code indisponível'), 'produção sem CPF e sem QR');

if (CupomImagem::disponivel()) {
    $png = CupomImagem::png([['t' => 'centro', 'texto' => 'Teste'], ['t' => 'qrcode', 'dados' => $url, 'texto' => $url]], 80);
    $img = imagecreatefromstring($png);
    ok($img !== false && imagesx($img) === 576 && imagesy($img) > 300, 'cupom com QR Code desenhado');
}

// ---------- Justificativa da SEFAZ ----------
ok(GestaoNotas::justificativa('curta') === null && GestaoNotas::justificativa(str_repeat('a', 256)) === null, 'justificativa fora do tamanho');
ok(GestaoNotas::justificativa("  Cliente   desistiu\nda compra  ") === 'Cliente desistiu da compra', 'justificativa normalizada');

echo "fiscal-danfe: ok\n";
