<?php

declare(strict_types=1);

// Teste sem banco e sem rede das regras de entrega: php tests/unit/entrega.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;
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

if ($falhas > 0) {
    fwrite(STDERR, "$falhas falha(s).\n");
    exit(1);
}
echo "OK: regras de entrega (distancia, bairro, faixas de km).\n";
