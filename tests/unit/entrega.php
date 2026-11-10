<?php

declare(strict_types=1);

// Teste sem banco e sem rede das regras de entrega: php tests/unit/entrega.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

// Distancia em linha reta (Haversine): Praca da Se -> Av. Paulista (MASP) ~ 2,9 km.
$d = Distancia::km(-23.5503, -46.6340, -23.5614, -46.6559);
$ok($d > 2.4 && $d < 2.8, 'distancia Se -> MASP (' . round($d, 2) . ' km)');
$ok(Distancia::km(-3.73, -38.52, -3.73, -38.52) === 0.0, 'mesmo ponto = 0 km');
$ok(Distancia::coordenadaValida('-3.7', '-38.5'), 'coordenada valida');
$ok(!Distancia::coordenadaValida('abc', '1'), 'coordenada texto recusada');
$ok(!Distancia::coordenadaValida(91, 0), 'latitude fora do globo recusada');
$ok(!Distancia::coordenadaValida(null, null), 'sem coordenada');

// Nomes de bairro e cidade comparados sem acento, maiuscula ou espacos extras.
$ok(TextoLocal::chave('  Cerqueira   César ') === TextoLocal::chave('cerqueira cesar'), 'bairro sem acento e espacos');
$ok(TextoLocal::chave('São Paulo') === TextoLocal::chave('SAO PAULO'), 'cidade sem acento e maiuscula');
$ok(TextoLocal::limpo("  Rua\n  A  ", 10) === 'Rua A', 'texto limpo');

$area = static fn (int $id, string $tipo, ?string $bairro, ?string $cidade, ?string $raio, string $taxa, int $ativo = 1): array =>
    ['id' => $id, 'tipo' => $tipo, 'bairro' => $bairro, 'cidade' => $cidade, 'raio_km' => $raio, 'taxa' => $taxa, 'pedido_minimo' => null, 'ativo' => $ativo];
$areas = [
    $area(1, 'raio', null, null, '6.00', '12.00'),
    $area(2, 'raio', null, null, '3.00', '7.50'),
    $area(3, 'bairro', 'Pinheiros', 'São Paulo', null, '9.00'),
    $area(4, 'bairro', 'Moema', 'São Paulo', null, '5.00', 0),
];

// 1) Bairro cadastrado tem prioridade, mesmo longe.
$r = AreaEntrega::escolher($areas, 'pinheiros', 'Sao Paulo', 20.0);
$ok($r['area']['id'] === 3 && $r['motivo'] === 'bairro', 'bairro cadastrado vence a distancia');
// Mesmo nome de bairro em outra cidade nao vale.
$r = AreaEntrega::escolher($areas, 'Pinheiros', 'Campinas', 2.0);
$ok($r['area']['id'] === 2, 'bairro de outra cidade cai na faixa de km');
// 2) Menor faixa que alcanca.
$ok(AreaEntrega::escolher($areas, 'Centro', 'São Paulo', 2.5)['area']['id'] === 2, '2,5 km -> faixa de 3 km');
$ok(AreaEntrega::escolher($areas, 'Centro', 'São Paulo', 3.0)['area']['id'] === 2, '3 km exatos -> faixa de 3 km');
$ok(AreaEntrega::escolher($areas, 'Centro', 'São Paulo', 4.2)['area']['id'] === 1, '4,2 km -> faixa de 6 km');
// 3) Fora de tudo.
$ok(AreaEntrega::escolher($areas, 'Centro', 'São Paulo', 6.5)['motivo'] === 'fora_raio', '6,5 km -> fora da area');
$ok(AreaEntrega::escolher($areas, 'Centro', 'São Paulo', null)['motivo'] === 'sem_localizacao', 'sem coordenadas com faixa de km');
// Area pausada nao vale.
$ok(AreaEntrega::escolher($areas, 'Moema', 'São Paulo', 7.0)['motivo'] === 'fora_raio', 'bairro pausado nao entrega');
// So bairros cadastrados.
$soBairros = [$area(3, 'bairro', 'Pinheiros', 'São Paulo', null, '9.00')];
$ok(AreaEntrega::escolher($soBairros, 'Centro', 'São Paulo', null)['motivo'] === 'fora_bairro', 'bairro nao cadastrado');
$ok(AreaEntrega::escolher([], 'Centro', 'São Paulo', 1.0)['motivo'] === 'sem_areas', 'nenhuma area');
$ok(!AreaEntrega::precisaDeLocalizacao($soBairros) && AreaEntrega::precisaDeLocalizacao($areas), 'precisa de localizacao so com faixa de km');

// Textos para o cliente.
$ok(AreaEntrega::descricao($areas[1]) === 'Até 3 km do restaurante', 'descricao da faixa');
$ok(AreaEntrega::descricao($areas[2]) === 'Bairro Pinheiros (São Paulo)', 'descricao do bairro');
$ok(AreaEntrega::km('2.50') === '2,5 km', 'km formatado');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: regras de entrega (distancia, bairro, faixas de km).\n";
