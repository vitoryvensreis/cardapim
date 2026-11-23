<?php

declare(strict_types=1);

// Teste sem banco e sem rede das regras de entrega: php tests/unit/entrega.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Services\Geo\MapaBase;
use Cardapim\Services\Pedidos\Endereco;
use Cardapim\Services\Pedidos\VerificadorEntrega;
use Cardapim\Repositories\Plataforma\GoogleMapsRepository;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Services\Pedidos\HistoricoCliente;
use Cardapim\Services\Pedidos\StatusPedido;

$falhas = 0;
$ok = static function (bool $condicao, string $descricao) use (&$falhas): void {
    if (!$condicao) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $descricao\n");
    }
};

// Haversine continua sendo o motor padrao: nenhuma alteracao ao ativar o recurso opcional.
 // Distancia em linha reta (Haversine): Praca da Se -> Av. Paulista (MASP) ~ 2,9 km.
$d = Distancia::km(-23.5503, -46.6340, -23.5614, -46.6559);
$ok($d > 2.4 && $d < 2.8, 'distancia Se -> MASP (' . round($d, 2) . ' km)');
$ok(Distancia::km(-3.73, -38.52, -3.73, -38.52) === 0.0, 'mesmo ponto = 0 km');
$ok(Distancia::coordenadaValida('-3.7', '-38.5'), 'coordenada valida');
$ok(!Distancia::coordenadaValida('abc', '1'), 'coordenada texto recusada');
$ok(!Distancia::coordenadaValida(91, 0), 'latitude fora do globo recusada');
$ok(!Distancia::coordenadaValida(null, null), 'sem coordenada');

// Haversine so descarta endereco fora da cobertura com GPS recente, preciso e margem de seguranca.
$ok(Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, -3.90, -38.52, 10, 5),
    'GPS preciso bem fora da cobertura dispensa Google');
$ok(!Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, -3.74, -38.52, 10, 5),
    'GPS dentro da cobertura exige Google para definir a faixa');
$ok(!Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, -3.90, -38.52, null, 5),
    'sem precisao do GPS nao descarta');
$ok(!Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, -3.90, -38.52, 100, 5),
    'GPS impreciso nao descarta');
$ok(!Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, null, -38.52, 10, 5),
    'sem coordenada do cliente nao descarta');
$ok(!Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, -3.90, -38.52, 10, 0),
    'sem faixa maxima valida nao descarta');
$ok(!Distancia::foraDoRaioComGpsConfiavel(-3.73, -38.52, -3.779, -38.52, 10, 5),
    'dentro da margem de seguranca consulta Google');

$gpsExemplo = ['logradouro' => 'Rua Teste', 'numero' => '12', 'bairro' => 'Centro',
    'cidade' => 'Fortaleza', 'estado' => 'CE', 'latitude' => -3.9, 'longitude' => -38.52,
    'gps_precisao_m' => 12.3, 'gps_em_ms' => (int) round(microtime(true) * 1000)];
$normalizadoGps = Endereco::normalizar($gpsExemplo);
$ok($normalizadoGps['erros'] === [] && $normalizadoGps['dados']['gps_precisao_m'] === 12.3,
    'GPS recente e preciso passa pela normalizacao do endereco');
$gpsAntigo = $gpsExemplo;
$gpsAntigo['gps_em_ms'] -= 3600000;
$ok(Endereco::normalizar($gpsAntigo)['dados']['gps_precisao_m'] === null,
    'GPS antigo nao autoriza rejeicao antecipada');
$gpsSemPrecisao = $gpsExemplo;
unset($gpsSemPrecisao['gps_precisao_m']);
$ok(Endereco::normalizar($gpsSemPrecisao)['dados']['gps_precisao_m'] === null,
    'GPS sem precisao continua podendo usar o Google');
$gpsAproximado = $gpsExemplo;
$gpsAproximado['gps_precisao_m'] = 300;
$ok(Endereco::normalizar($gpsAproximado)['dados']['gps_precisao_m'] === null,
    'coordenadas aproximadas nao autorizam o filtro');

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

// Integracao local: com Google global ativo, GPS inequivocamente fora economiza a consulta HTTP.
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $dbEntrega = new PDO('sqlite::memory:');
    $dbEntrega->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbEntrega->exec('CREATE TABLE configuracoes (chave TEXT PRIMARY KEY, valor TEXT, tipo TEXT, descricao TEXT)');
    $dbEntrega->exec('CREATE TABLE restaurantes (
        id INTEGER PRIMARY KEY, latitude REAL, longitude REAL,
        logradouro TEXT, numero TEXT, bairro TEXT, cidade TEXT, estado TEXT, cep TEXT
    )');
    $dbEntrega->exec("INSERT INTO restaurantes VALUES (1, -3.73, -38.52, 'Rua Teste', '10', 'Centro', 'Fortaleza', 'CE', '60000000')");
    $dbEntrega->exec('CREATE TABLE restaurante_entrega_pagamento (
        id_restaurante INTEGER PRIMARY KEY, aceita_entrega INTEGER, aceita_retirada INTEGER,
        entrega_minutos_min INTEGER, entrega_minutos_max INTEGER, retirada_minutos INTEGER,
        pag_pix_online INTEGER, pag_pix_entrega INTEGER, pag_debito INTEGER, pag_credito INTEGER, pag_dinheiro INTEGER
    )');
    $dbEntrega->exec('INSERT INTO restaurante_entrega_pagamento (id_restaurante, aceita_entrega, aceita_retirada) VALUES (1, 1, 1)');
    $dbEntrega->exec('CREATE TABLE restaurante_areas_entrega (
        id INTEGER PRIMARY KEY, id_restaurante INTEGER, tipo TEXT, bairro TEXT, cidade TEXT,
        raio_km TEXT, taxa TEXT, pedido_minimo TEXT, ativo INTEGER
    )');
    $dbEntrega->exec("INSERT INTO restaurante_areas_entrega VALUES (1, 1, 'raio', NULL, NULL, '5.00', '12.00', NULL, 1)");
    $dbEntrega->exec("INSERT INTO restaurante_areas_entrega VALUES (2, 1, 'bairro', 'Centro', 'Fortaleza', NULL, '7.00', NULL, 1)");
    Cripto::usarChave(str_repeat('g', 32));
    (new GoogleMapsRepository($dbEntrega))->salvar([
        'chave' => 'CHAVE_DUMMY_NUNCA_CHAMAR_HTTP', 'ativo' => true,
        'testado_em' => '2026-11-22', 'salvo_em' => '2026-11-22', 'salvo_por' => 'Teste',
    ]);
    $verificador = new VerificadorEntrega($dbEntrega);
    $enderecoDistante = Endereco::normalizar($gpsExemplo)['dados'];
    $enderecoDistante['bairro'] = 'Outro'; // Sem a regra prioritária do bairro.
    $rFora = $verificador->verificar(1, $enderecoDistante);
    $ok($rFora['entrega'] === false && $rFora['motivo'] === 'fora_raio',
        'Google ativo: Haversine preciso fora retorna fora_raio sem acessar HTTP');
    $enderecoBairro = $enderecoDistante;
    $enderecoBairro['bairro'] = 'Centro';
    $rBairro = $verificador->verificar(1, $enderecoBairro);
    $ok($rBairro['entrega'] === true && $rBairro['motivo'] === 'bairro' && $rBairro['taxa'] === '7.00',
        'Google ativo: bairro tem prioridade mesmo com GPS distante');
    Cripto::usarChave(null);
}

// O cliente acompanha todas as etapas, inclusive "Pronto" antes de sair para entrega.
$ok(StatusPedido::etapas('entrega') === ['recebido', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido'], 'etapas da entrega com Pronto');
$ok(StatusPedido::etapas('retirada') === ['recebido', 'em_preparo', 'pronto', 'concluido'], 'etapas da retirada');

// Meus pedidos: data amigavel e resumo dos itens.
$ok(HistoricoCliente::quando('2026-11-11 19:40:00', '2026-11-11') === 'Hoje, 19:40', 'quando: hoje');
$ok(HistoricoCliente::quando('2026-11-10 12:05:00', '2026-11-11') === 'Ontem, 12:05', 'quando: ontem');
$ok(HistoricoCliente::quando('2026-11-01 01:00:00', '2026-11-02') === 'Ontem, 01:00', 'quando: ontem na virada do mes');
$ok(HistoricoCliente::quando('2026-10-08 21:10:00', '2026-11-11') === '08/10, 21:10', 'quando: mesmo ano');
$ok(HistoricoCliente::quando('2025-12-31 23:59:00', '2026-01-02') === '31/12/2025, 23:59', 'quando: outro ano');
$ok(HistoricoCliente::resumo([['nome' => 'Cerveja', 'quantidade' => 2]]) === '2x Cerveja', 'resumo de 1 item');
$ok(HistoricoCliente::resumo([['nome' => 'A', 'quantidade' => 1], ['nome' => 'B', 'quantidade' => 2], ['nome' => 'C', 'quantidade' => 1], ['nome' => 'D', 'quantidade' => 1]]) === '1x A, 2x B e mais 2', 'resumo com mais itens');

// Avisos do pedido pelo WhatsApp do restaurante: texto de cada etapa.
$ped = ['restaurante_nome' => 'Bar do Zé', 'numero' => 42, 'cliente_nome' => 'Maria Souza', 'tipo' => 'entrega', 'entregador_nome' => 'Carlos Lima',
    'pagamento_status' => 'pendente', 'forma_pagamento' => 'dinheiro', 'troco_para' => '100.00', 'total' => '58.90', 'motivo_cancelamento' => null];
$link = 'https://cardapim.test/bar-do-ze/pedido/9';
$m = AvisosWhatsApp::mensagem($ped, 'recebido', $link);
$ok(str_starts_with($m, "*Bar do Zé*\nOlá, Maria! Recebemos seu pedido *#42*.") && str_ends_with($m, 'Acompanhe: ' . $link), 'aviso recebido com saudacao e link');
$m = AvisosWhatsApp::mensagem($ped, 'saiu_entrega', $link);
$ok(str_contains($m, 'saiu para entrega com Carlos.'), 'aviso saiu com primeiro nome do motoqueiro');
$ok(str_contains($m, 'Pagamento na entrega: R$ 58,90 (dinheiro, troco para R$ 100,00).'), 'aviso saiu com valor e troco');
$ok(str_ends_with($m, 'Veja a moto chegando no mapa: ' . $link), 'aviso saiu com link do mapa');
$pago = ['pagamento_status' => 'pago', 'forma_pagamento' => 'pix_online'] + $ped;
$ok(!str_contains(AvisosWhatsApp::mensagem($pago, 'saiu_entrega', $link), 'Pagamento na entrega'), 'pedido pago nao cobra na entrega');
$ok(str_contains(AvisosWhatsApp::mensagem(['tipo' => 'retirada'] + $ped, 'pronto', $link), 'Pode vir retirar!'), 'pronto na retirada');
$ok(str_contains(AvisosWhatsApp::mensagem($ped, 'pronto', $link), 'já vai sair para entrega'), 'pronto na entrega');
$m = AvisosWhatsApp::mensagem(['motivo_cancelamento' => 'Cancelado pelo restaurante: Acabou o gás'] + $ped, 'cancelado', $link);
$ok(str_contains($m, "foi cancelado.\nMotivo: Acabou o gás") && !str_contains($m, 'Cancelado pelo'), 'cancelado com motivo limpo');
$ok(!str_contains(AvisosWhatsApp::mensagem(['cliente_nome' => ''] + $ped, 'recebido', $link), 'Olá'), 'sem nome, sem saudacao');
$ok(str_contains(AvisosWhatsApp::mensagem($ped, 'concluido', $link), 'Pedido *#42* entregue.'), 'concluido entregue');

// Mapa proprio: so aparece com um PMTiles de verdade em public/mapas; senao a tela usa o OpenStreetMap.
$pub = sys_get_temp_dir() . '/cardapim-mapa-' . bin2hex(random_bytes(4));
mkdir($pub . '/mapas', 0700, true);
$ok(MapaBase::url($pub) === null, 'sem arquivo: sem mapa proprio');
file_put_contents($pub . '/mapas/ceara.pmtiles', 'PMTiles');
$ok(MapaBase::url($pub) === null, 'arquivo menor que o cabecalho: ignorado');
file_put_contents($pub . '/mapas/ceara.pmtiles', 'PMTiles' . chr(3) . str_repeat("\0", 200));
touch($pub . '/mapas/ceara.pmtiles', 1763000000);
$ok(MapaBase::url($pub) === '/mapas/ceara.pmtiles?v=' . base_convert('1763000000', 10, 36), 'com arquivo: URL com versao pela data');
unlink($pub . '/mapas/ceara.pmtiles');
rmdir($pub . '/mapas');
rmdir($pub);

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: regras de entrega (distancia, bairro, faixas de km, mapa proprio).\n";
